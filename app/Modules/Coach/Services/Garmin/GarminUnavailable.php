<?php

namespace App\Modules\Coach\Services\Garmin;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Garmin refuse ou échoue (clé invalide ou expirée, limite de requêtes, panne…) : une explication lisible
 * pour l'athlète, et le détail technique à transmettre à celui qui gère le site.
 */
class GarminUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $detail, public readonly bool $rateLimited = false)
    {
        parent::__construct('API Garmin : '.$detail);
    }

    public static function fromResponse(Response $response, string $path, bool $tokenRequest = false): self
    {
        $detail = 'HTTP '.$response->status().' sur '.$path.' — '.mb_strimwidth(trim($response->body()) ?: 'sans message', 0, 200, '…');

        $reason = match (true) {
            $response->status() === 429 => 'Garmin limite le nombre de demandes pour le moment (réessayer un peu plus tard)',
            $tokenRequest && in_array($response->status(), [400, 401, 403], true) => 'Garmin refuse la clé (elle est peut-être erronée, supprimée ou expirée : '
                .'il faut en créer une nouvelle dans Garmin Connect et la coller dans le profil)',
            in_array($response->status(), [401, 403], true) => 'Garmin refuse l\'accès à ces données',
            $response->serverError() => 'Garmin ne répond pas correctement pour le moment',
            default => 'Garmin a renvoyé une erreur',
        };

        return new self($reason, $detail, $response->status() === 429);
    }
}
