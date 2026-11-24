<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Jobs\SyncStravaJob;
use App\Modules\Coach\Models\StravaAccount;
use App\Modules\Coach\Services\Strava\StravaClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connexion du compte Strava de l'athlète (OAuth), synchronisation manuelle, déconnexion.
 */
class StravaController extends Controller
{
    public function connect(Request $request, StravaClient $client): RedirectResponse
    {
        abort_unless(filled(config('services.strava.client_id')), 503, 'Strava n\'est pas encore configuré (STRAVA_CLIENT_ID).');

        $state = Str::random(40);
        $request->session()->put('strava_state', $state);

        return redirect()->away($client->authorizeUrl($state, route('coach.strava.callback')));
    }

    /** Retour de Strava après l'autorisation. */
    public function callback(Request $request, StravaClient $client): RedirectResponse
    {
        $expected = $request->session()->pull('strava_state');
        abort_unless($expected && hash_equals($expected, (string) $request->query('state')), 403, 'Lien de connexion Strava expiré : recommence.');

        if ($request->query('error') || ! $request->query('code')) {
            return to_route('coach.profile.edit')->with('status', 'Connexion à Strava annulée.');
        }

        $scopes = explode(',', (string) $request->query('scope'));
        if (! in_array('activity:read_all', $scopes, true)) {
            return to_route('coach.profile.edit')->with('status', 'Pour que le coach voie tes courses, laisse cochée l\'autorisation « voir tes activités », y compris privées.');
        }

        try {
            $tokens = $client->exchangeCode((string) $request->query('code'));
        } catch (Throwable $e) {
            Log::warning('Strava : échec de la connexion', ['error' => $e->getMessage()]);

            return to_route('coach.profile.edit')->with('status', 'Strava n\'a pas validé la connexion. Réessaie dans un instant.');
        }

        $athleteId = (int) $tokens['athlete']['id'];
        $linkedElsewhere = StravaAccount::where('athlete_id', $athleteId)->where('user_id', '!=', $request->user()->id)->exists();
        if ($linkedElsewhere) {
            return to_route('coach.profile.edit')->with('status', 'Ce compte Strava est déjà relié à un autre compte du hub.');
        }

        $account = StravaAccount::ownedBy($request->user())->first() ?? (new StravaAccount)->user()->associate($request->user());
        $account->fill([
            'athlete_id' => $athleteId,
            'athlete_name' => trim(($tokens['athlete']['firstname'] ?? '').' '.($tokens['athlete']['lastname'] ?? '')) ?: null,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => now()->setTimestamp((int) $tokens['expires_at']),
            'scopes' => implode(',', $scopes),
        ])->save();

        SyncStravaJob::dispatch($account->id);

        return to_route('coach.profile.edit')->with('status', 'Strava relié ! Import de tes '.config('coach.strava.backfill_weeks').' dernières semaines en cours.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $account = StravaAccount::ownedBy($request->user())->firstOrFail();
        SyncStravaJob::dispatch($account->id);

        return back()->with('status', 'Synchronisation lancée : tes nouvelles courses arrivent dans quelques instants.');
    }

    /** Déconnexion : l'accès est révoqué chez Strava ; les courses déjà importées restent. */
    public function disconnect(Request $request, StravaClient $client): RedirectResponse
    {
        $account = StravaAccount::ownedBy($request->user())->firstOrFail();

        try {
            $client->deauthorize($account);
        } catch (Throwable $e) {
            Log::info('Strava : révocation impossible, compte retiré quand même', ['error' => $e->getMessage()]);
        }
        $account->delete();

        return to_route('coach.profile.edit')->with('status', 'Strava déconnecté. Tes courses déjà importées sont conservées.');
    }
}
