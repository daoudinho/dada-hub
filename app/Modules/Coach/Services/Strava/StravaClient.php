<?php

namespace App\Modules\Coach\Services\Strava;

use App\Modules\Coach\Models\StravaAccount;
use App\Services\RetriesTransientErrors;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * API Strava v3 : connexion OAuth, jetons renouvelés automatiquement, lecture des activités.
 *
 * Limites de l'API (lecture) : 100 requêtes par quart d'heure et 1 000 par jour pour l'application.
 * Un dépassement lève StravaRateLimited : les tâches se replanifient au quart d'heure suivant.
 */
class StravaClient
{
    use RetriesTransientErrors;

    public const API = 'https://www.strava.com/api/v3';

    public const OAUTH = 'https://www.strava.com/oauth';

    /** Lecture de toutes les activités (y compris privées) et du profil (chaussures). */
    public const SCOPES = 'read,activity:read_all,profile:read_all';

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return self::OAUTH.'/authorize?'.http_build_query([
            'client_id' => config('services.strava.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'approval_prompt' => 'auto',
            'scope' => self::SCOPES,
            'state' => $state,
        ]);
    }

    /**
     * Échange le code de retour contre les jetons et le profil de l'athlète.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int, athlete: array<string, mixed>}
     */
    public function exchangeCode(string $code): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code]);
    }

    /**
     * GET authentifié au nom de l'athlète.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws StravaRateLimited
     * @throws StravaUnavailable accès refusé ou erreur de Strava
     */
    public function get(StravaAccount $account, string $path, array $query = []): array
    {
        $response = $this->request($account)->get(self::API.$path, $query);

        if ($response->status() === 429) {
            throw new StravaRateLimited;
        }
        if ($response->status() === 404) {
            return [];
        }
        if ($response->failed()) {
            throw StravaUnavailable::fromResponse($response, $path);
        }

        return $response->json() ?? [];
    }

    /** Révoque l'accès de l'application au compte (déconnexion). */
    public function deauthorize(StravaAccount $account): void
    {
        $this->request($account)->post(self::OAUTH.'/deauthorize');
    }

    /** Requête au nom de l'athlète, avec un jeton valide (renouvelé s'il expire). */
    private function request(StravaAccount $account): PendingRequest
    {
        if ($account->tokenExpired()) {
            $tokens = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $account->refresh_token]);
            $account->update([
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_at' => now()->setTimestamp($tokens['expires_at']),
            ]);
        }

        return Http::withToken($account->access_token)
            ->acceptJson()
            ->timeout(30)
            // Réseau ou 5xx : nouvel essai. 429 : pas d'essai immédiat, la tâche se replanifie.
            ->retry(2, 3000, fn (\Throwable $e) => $this->isTransient($e) && ! ($e instanceof RequestException && $e->response->status() === 429), throw: false);
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function token(array $params): array
    {
        /** @var Response $response */
        $response = Http::asForm()->timeout(30)->post(self::OAUTH.'/token', $params + [
            'client_id' => config('services.strava.client_id'),
            'client_secret' => config('services.strava.client_secret'),
        ]);

        if ($response->status() === 429) {
            throw new StravaRateLimited;
        }
        if ($response->failed() || ! $response->json('access_token')) {
            throw new RuntimeException('Strava : échec de l\'authentification (HTTP '.$response->status().') '.$response->json('message'));
        }

        return $response->json();
    }
}
