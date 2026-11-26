<?php

namespace App\Modules\Coach\Services\GoogleHealth;

use App\Modules\Coach\Models\GoogleHealthAccount;
use App\Services\RetriesTransientErrors;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Health API v4 : connexion OAuth Google, jetons renouvelés automatiquement, lecture des données de santé.
 *
 * Tant que l'application Google est « en test », Google fait expirer le jeton de renouvellement au bout de 7 jours :
 * l'athlète doit alors relier son compte de nouveau (needsReconnect()).
 */
class GoogleHealthClient
{
    use RetriesTransientErrors;

    public const API = 'https://health.googleapis.com/v4';

    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** Séances d'exercice (indispensable). */
    public const ACTIVITY_SCOPE = 'https://www.googleapis.com/auth/googlehealth.activity_and_fitness.readonly';

    /** Fréquence cardiaque seconde par seconde (facultatif). */
    public const HEART_RATE_SCOPE = 'https://www.googleapis.com/auth/googlehealth.health_metrics_and_measurements.readonly';

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.google_health.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::ACTIVITY_SCOPE.' '.self::HEART_RATE_SCOPE,
            // Hors ligne + consentement redemandé : Google renvoie un jeton de renouvellement à chaque connexion.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Échange le code de retour contre les jetons.
     *
     * @return array{access_token: string, refresh_token?: string, expires_in: int, scope?: string}
     *
     * @throws GoogleHealthUnavailable
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
        if ($response->failed() || ! $response->json('access_token')) {
            throw new GoogleHealthUnavailable('Google n\'a pas validé la connexion', 'HTTP '.$response->status().' à l\'échange du code — '.($response->json('error') ?? 'sans message'));
        }

        return $response->json();
    }

    /**
     * Identifiant Google Health de l'athlète, avec un jeton d'accès tout juste obtenu.
     *
     * @throws GoogleHealthUnavailable
     */
    public function healthUserId(string $accessToken): string
    {
        $response = $this->http($accessToken)->get(self::API.'/users/me/identity');
        if ($response->failed() || ! $response->json('healthUserId')) {
            throw GoogleHealthUnavailable::fromResponse($response, '/users/me/identity');
        }

        return (string) $response->json('healthUserId');
    }

    /**
     * GET authentifié au nom de l'athlète.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws GoogleHealthUnavailable
     */
    public function get(GoogleHealthAccount $account, string $path, array $query = []): array
    {
        $response = $this->http($this->accessToken($account))->get(self::API.$path, $query);

        // Jeton refusé avant son échéance : un nouvel essai avec un jeton renouvelé.
        if ($response->status() === 401) {
            $account->forceFill(['expires_at' => null])->save();
            $response = $this->http($this->accessToken($account))->get(self::API.$path, $query);
        }

        if ($response->status() === 404) {
            return [];
        }
        if ($response->failed()) {
            throw GoogleHealthUnavailable::fromResponse($response, $path);
        }

        return $response->json() ?? [];
    }

    /** Révoque l'accès de l'application au compte Google (déconnexion). */
    public function revoke(GoogleHealthAccount $account): void
    {
        $token = $account->refresh_token ?? $account->access_token;
        if ($token) {
            Http::asForm()->timeout(15)->post(self::REVOKE_URL, ['token' => $token]);
        }
    }

    /**
     * Jeton d'accès valide, renouvelé s'il expire. Renouvellement refusé : le compte est marqué « à reconnecter ».
     *
     * @throws GoogleHealthUnavailable
     */
    private function accessToken(GoogleHealthAccount $account): string
    {
        if ($account->needsReconnect()) {
            throw new GoogleHealthUnavailable('l\'autorisation Google a expiré : relie Google Health de nouveau dans le profil', 'jeton de renouvellement absent', reconnect: true);
        }
        if (! $account->tokenExpired()) {
            return $account->access_token;
        }

        $response = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $account->refresh_token]);
        if (in_array($response->json('error'), ['invalid_grant', 'unauthorized_client'], true)) {
            $account->forceFill(['access_token' => null, 'refresh_token' => null, 'expires_at' => null])->save();

            throw GoogleHealthUnavailable::refreshRefused($response);
        }
        if ($response->failed() || ! $response->json('access_token')) {
            throw new GoogleHealthUnavailable('Google ne répond pas correctement pour le moment', 'HTTP '.$response->status().' au renouvellement du jeton — '.($response->json('error') ?? 'sans message'));
        }

        $account->forceFill([
            'access_token' => $response->json('access_token'),
            // Google ne renvoie un nouveau jeton de renouvellement que rarement : sinon on garde l'actuel.
            'refresh_token' => $response->json('refresh_token') ?? $account->refresh_token,
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ])->save();

        return $account->access_token;
    }

    private function http(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 3000, fn (\Throwable $e) => $this->isTransient($e), throw: false);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function token(array $params): Response
    {
        return Http::asForm()->timeout(30)
            ->retry(2, 3000, fn (\Throwable $e) => $this->isTransient($e), throw: false)
            ->post(self::TOKEN_URL, $params + [
                'client_id' => config('services.google_health.client_id'),
                'client_secret' => config('services.google_health.client_secret'),
            ]);
    }
}
