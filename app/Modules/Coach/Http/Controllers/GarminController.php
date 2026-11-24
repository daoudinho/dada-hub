<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Jobs\SyncGarminJob;
use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Services\Duration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Clé d'API personnelle Garmin de l'athlète : enregistrement (qui lance la première synchronisation), synchronisation, suppression.
 */
class GarminController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['api_key' => trim((string) $request->input('api_key'))]);
        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:16', 'max:2000', 'regex:/^\S+$/'],
            'key_expires_on' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'api_key.regex' => 'La clé ne doit pas contenir d\'espace : colle-la telle que Garmin l\'affiche.',
            'api_key.min' => 'Cette clé semble incomplète : copie-la en entier depuis Garmin Connect.',
        ]);

        $account = GarminAccount::ownedBy($request->user())->first() ?? (new GarminAccount)->user()->associate($request->user());
        $isNew = ! $account->exists;
        // Nouvelle clé : l'ancien jeton du jour n'est plus valable.
        $account->fill($data + ['access_token' => null, 'token_expires_at' => null, 'last_error' => null])->save();

        SyncGarminJob::dispatch($account->id);

        return to_route('coach.profile.edit')->with('status', $isNew
            ? 'Clé Garmin enregistrée. Import de tes courses des '.SyncGarminJob::BACKFILL_YEARS.' dernières années en cours.'
            : 'Clé Garmin remplacée. Synchronisation lancée.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $account = GarminAccount::ownedBy($request->user())->firstOrFail();
        SyncGarminJob::dispatch($account->id);

        // La page suit l'avancement (status) et annonce les nouvelles courses.
        return back()->with('garmin_sync', now()->toIso8601String());
    }

    /** Avancement d'une synchronisation lancée à « depuis » : terminée ?, nouvelles courses importées depuis. */
    public function status(Request $request): JsonResponse
    {
        $since = Carbon::parse($request->validate(['depuis' => ['required', 'date']])['depuis']);
        $account = GarminAccount::ownedBy($request->user())->firstOrFail();
        $done = $account->syncFinishedSince($since);

        return response()->json([
            'done' => $done,
            'error' => $account->syncFailedSince($since) ? $account->last_error : null,
            // Les courses ne sont annoncées qu'à la fin : inutile de les lire à chaque interrogation.
            'runs' => ! $done ? [] : RunningActivity::ownedBy($request->user())->where('source', 'garmin')->where('created_at', '>=', $since)
                ->orderBy('started_at')->get(['id', 'distance_m', 'moving_seconds', 'new_records'])
                ->map(fn (RunningActivity $run) => [
                    'label' => number_format($run->distanceKm(), 1, ',', '').' km en '.Duration::format($run->moving_seconds),
                    'record' => (bool) $run->new_records,
                    'url' => route('coach.running.activities.show', $run),
                ])->values(),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        GarminAccount::ownedBy($request->user())->delete();

        return to_route('coach.profile.edit')->with('status', 'Clé Garmin supprimée. Tes courses déjà importées sont conservées.');
    }
}
