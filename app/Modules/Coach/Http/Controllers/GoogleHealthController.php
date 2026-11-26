<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Jobs\SyncGoogleHealthJob;
use App\Modules\Coach\Models\GoogleHealthAccount;
use App\Modules\Coach\Services\GoogleHealth\GoogleHealthClient;
use App\Modules\Coach\Services\GoogleHealth\GoogleHealthUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connexion du compte Google de l'athlète à Google Health (OAuth), synchronisation manuelle, déconnexion.
 */
class GoogleHealthController extends Controller
{
    public function connect(Request $request, GoogleHealthClient $client): RedirectResponse
    {
        abort_unless(filled(config('services.google_health.client_id')), 503, 'Google Health n\'est pas encore configuré (GOOGLE_HEALTH_CLIENT_ID).');

        $state = Str::random(40);
        $request->session()->put('google_health_state', $state);

        return redirect()->away($client->authorizeUrl($state, route('coach.google-health.callback')));
    }

    /** Retour de Google après l'autorisation. */
    public function callback(Request $request, GoogleHealthClient $client): RedirectResponse
    {
        $expected = $request->session()->pull('google_health_state');
        abort_unless($expected && hash_equals($expected, (string) $request->query('state')), 403, 'Lien de connexion Google expiré : recommence.');

        if ($request->query('error') || ! $request->query('code')) {
            return to_route('coach.profile.edit')->with('status', 'Connexion à Google Health annulée.');
        }

        try {
            $tokens = $client->exchangeCode((string) $request->query('code'), route('coach.google-health.callback'));
        } catch (GoogleHealthUnavailable $e) {
            Log::warning('Google Health : échec de la connexion', ['error' => $e->getMessage()]);

            return to_route('coach.profile.edit')->with('status', 'Google n\'a pas validé la connexion. Réessaie dans un instant.');
        }

        $scopes = explode(' ', (string) ($tokens['scope'] ?? ''));
        if (! in_array(GoogleHealthClient::ACTIVITY_SCOPE, $scopes, true)) {
            return to_route('coach.profile.edit')->with('status', 'Pour que le coach voie tes courses, laisse cochée l\'autorisation « activité et forme physique ».');
        }

        try {
            $healthUserId = $client->healthUserId($tokens['access_token']);
        } catch (GoogleHealthUnavailable $e) {
            Log::warning('Google Health : identité illisible', ['error' => $e->getMessage()]);

            return to_route('coach.profile.edit')->with('status', 'Connexion impossible : '.$e->reason.'.');
        }

        $linkedElsewhere = GoogleHealthAccount::where('health_user_id', $healthUserId)->where('user_id', '!=', $request->user()->id)->exists();
        if ($linkedElsewhere) {
            return to_route('coach.profile.edit')->with('status', 'Ce compte Google est déjà relié à un autre compte du hub.');
        }

        $account = GoogleHealthAccount::ownedBy($request->user())->first() ?? (new GoogleHealthAccount)->user()->associate($request->user());
        $isNew = ! $account->exists;
        $account->fill([
            'health_user_id' => $healthUserId,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $account->refresh_token,
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'scopes' => implode(' ', $scopes),
            'last_error' => null,
        ])->save();

        SyncGoogleHealthJob::dispatch($account->id);

        return to_route('coach.profile.edit')->with('status', $isNew
            ? 'Google Health relié ! Import de tes courses des '.config('coach.google_health.backfill_months').' derniers mois en cours.'
            : 'Google Health relié de nouveau. Synchronisation lancée.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $account = GoogleHealthAccount::ownedBy($request->user())->firstOrFail();
        if ($account->needsReconnect()) {
            return to_route('coach.profile.edit')->with('status', 'L\'autorisation Google a expiré : relie Google Health de nouveau.');
        }

        SyncGoogleHealthJob::dispatch($account->id);

        return back()->with('status', 'Synchronisation lancée : tes nouvelles courses arrivent dans quelques instants.');
    }

    /** Déconnexion : l'accès est révoqué chez Google ; les courses déjà importées restent. */
    public function disconnect(Request $request, GoogleHealthClient $client): RedirectResponse
    {
        $account = GoogleHealthAccount::ownedBy($request->user())->firstOrFail();

        try {
            $client->revoke($account);
        } catch (Throwable $e) {
            Log::info('Google Health : révocation impossible, compte retiré quand même', ['error' => $e->getMessage()]);
        }
        $account->delete();

        return to_route('coach.profile.edit')->with('status', 'Google Health déconnecté. Tes courses déjà importées sont conservées.');
    }
}
