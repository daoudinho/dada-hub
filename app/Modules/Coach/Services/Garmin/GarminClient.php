<?php

namespace App\Modules\Coach\Services\Garmin;

use App\Modules\Coach\Models\GarminAccount;
use App\Services\RetriesTransientErrors;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * API personnelle Garmin Connect : la clé de l'athlète est échangée contre un jeton valable 24 h (sans renouvellement :
 * on en redemande un), puis les données se lisent avec ce jeton.
 *
 * Particularités constatées : Garmin refuse « Accept: application/json » (406) et renvoie le jeton en texte brut (JWT).
 */
class GarminClient
{
    use RetriesTransientErrors;

    public const API = 'https://personal.connectapi.garmin.com';

    private const TOKEN_PATH = '/personalapitoken-service/personal-api/personal-api-key/token';

    /**
     * GET authentifié : tableau JSON (vide si la donnée n'existe pas).
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws GarminUnavailable
     */
    public function get(GarminAccount $account, string $path, array $query = []): array
    {
        $response = $this->request($account)->get(self::API.$path, $query);

        // Jeton refusé (révoqué entre-temps) : un nouvel essai avec un jeton neuf.
        if ($response->status() === 401) {
            $account->forceFill(['access_token' => null, 'token_expires_at' => null])->save();
            $response = $this->request($account)->get(self::API.$path, $query);
        }

        return $this->decode($response, $path);
    }

    /**
     * Fichier binaire (archive .FIT).
     *
     * @throws GarminUnavailable
     */
    public function download(GarminAccount $account, string $path): string
    {
        $response = $this->request($account)->get(self::API.$path);
        if ($response->failed()) {
            throw GarminUnavailable::fromResponse($response, $path);
        }

        return $response->body();
    }

    /**
     * @return array<mixed>
     */
    private function decode(Response $response, string $path): array
    {
        if (in_array($response->status(), [204, 404], true)) {
            return [];
        }
        if ($response->failed()) {
            throw GarminUnavailable::fromResponse($response, $path);
        }

        return $response->json() ?? [];
    }

    private function request(GarminAccount $account): PendingRequest
    {
        return Http::withToken($this->token($account))
            ->withHeaders(['Accept' => '*/*'])
            ->timeout(60)
            ->retry(2, 3000, fn (\Throwable $e) => $this->isTransient($e), throw: false);
    }

    /** Jeton du jour, redemandé une minute avant son expiration. */
    private function token(GarminAccount $account): string
    {
        if ($account->access_token && $account->token_expires_at?->isAfter(now()->addMinute())) {
            return $account->access_token;
        }

        if ($account->keyExpired()) {
            throw new GarminUnavailable('la clé Garmin a expiré (il faut en créer une nouvelle dans Garmin Connect et la coller dans le profil)', 'clé expirée le '.$account->key_expires_on->format('d/m/Y'));
        }

        $response = Http::withHeaders(['Authorization' => 'ApiKey '.$account->api_key, 'Accept' => '*/*'])
            ->timeout(30)
            ->retry(2, 3000, fn (\Throwable $e) => $this->isTransient($e), throw: false)
            ->post(self::API.self::TOKEN_PATH);

        if ($response->failed()) {
            throw GarminUnavailable::fromResponse($response, self::TOKEN_PATH, tokenRequest: true);
        }

        $token = trim($response->body());
        $account->forceFill(['access_token' => $token, 'token_expires_at' => $this->expiry($token)])->save();

        return $token;
    }

    /** Date d'expiration lue dans le jeton (JWT), 24 h par défaut. */
    private function expiry(string $token): \DateTimeInterface
    {
        $payload = json_decode((string) base64_decode(strtr(explode('.', $token)[1] ?? '', '-_', '+/')), true);

        return isset($payload['exp']) ? now()->setTimestamp((int) $payload['exp']) : now()->addDay();
    }
}
