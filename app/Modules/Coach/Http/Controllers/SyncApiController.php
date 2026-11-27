<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\SyncAccount;
use App\Modules\Coach\Services\SamsungHealth\SamsungHealthImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API de l'app Android Dada Sync (clé personnelle, voir AuthenticateSyncAccount) : état de la liaison, réception des courses.
 */
class SyncApiController extends Controller
{
    /** Nombre maximal de courses par envoi. */
    private const MAX_RUNS = 20;

    /** Points au plus par tracé ou journal (environ 14 h à un point par seconde). */
    private const MAX_POINTS = 50000;

    /** La clé fonctionne : l'app affiche le prénom de l'athlète et reprend après la dernière course reçue. */
    public function status(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json([
            'athlete' => strtok($account->user->name, ' '),
            'last_run_at' => RunningActivity::ownedBy($account->user)->whereNotNull('device_synced_at')->max('started_at'),
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
        ]);
    }

    /** Lot de courses lues dans Samsung Health : chacune est créée, complétée (course déjà présente) ou mise à jour. */
    public function runs(Request $request, SamsungHealthImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'history' => ['nullable', 'boolean'],
            'runs' => ['required', 'array', 'min:1', 'max:'.self::MAX_RUNS],
            'runs.*.id' => ['required', 'string', 'max:100'],
            'runs.*.type' => ['required', Rule::in(SamsungHealthImporter::RUN_TYPES)],
            'runs.*.title' => ['nullable', 'string', 'max:150'],
            'runs.*.start' => ['required', 'date'],
            'runs.*.end' => ['required', 'date', 'after:runs.*.start'],
            'runs.*.duration_s' => ['nullable', 'numeric', 'between:1,172800'],
            'runs.*.distance_m' => ['nullable', 'numeric', 'between:0,400000'],
            'runs.*.mean_hr' => ['nullable', 'numeric', 'between:20,250'],
            'runs.*.max_hr' => ['nullable', 'numeric', 'between:20,250'],
            'runs.*.mean_cadence' => ['nullable', 'numeric', 'between:0,400'],
            'runs.*.elevation_gain_m' => ['nullable', 'numeric', 'between:0,20000'],
            'runs.*.route' => ['nullable', 'array', 'max:'.self::MAX_POINTS],
            'runs.*.samples' => ['nullable', 'array', 'max:'.self::MAX_POINTS],
        ]);
        $account = $this->account($request);

        // L'historique (premier envoi) n'ouvre pas de débrief : seules les nouvelles courses récentes en ouvrent un.
        $results = collect($data['runs'])->map(fn (array $run) => $importer->import($account->user, $run, notify: ! ($data['history'] ?? false)));

        $account->forceFill(['last_synced_at' => now(), 'last_sync_runs' => $results->count()])->save();

        return response()->json([
            'received' => $results->count(),
            'created' => $results->where('status', 'created')->count(),
            'merged' => $results->where('status', 'merged')->count(),
            'updated' => $results->where('status', 'updated')->count(),
        ]);
    }

    private function account(Request $request): SyncAccount
    {
        return $request->attributes->get('syncAccount');
    }
}
