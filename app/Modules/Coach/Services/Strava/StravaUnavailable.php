<?php

namespace App\Modules\Coach\Services\Strava;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Strava refuse ou échoue (accès révoqué, application inactive, panne…) : une explication lisible
 * pour l'athlète, et le détail technique à transmettre à celui qui gère le site.
 */
class StravaUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $detail)
    {
        parent::__construct('API Strava : '.$detail);
    }

    public static function fromResponse(Response $response, string $path): self
    {
        $codes = collect($response->json('errors') ?? [])
            ->map(fn (array $error) => trim(($error['resource'] ?? '').' '.($error['field'] ?? '').' '.($error['code'] ?? '')))
            ->filter()->implode(', ');
        $detail = 'HTTP '.$response->status().' sur '.$path.' — '.($response->json('message') ?? 'sans message').($codes ? ' ('.$codes.')' : '');

        $reason = match (true) {
            str_contains($codes, 'Application Status Inactive') => 'l\'application Strava du site est inactive '
                .'(Strava exige un abonnement payant sur le compte qui a créé l\'application)',
            $response->status() === 401 => 'l\'autorisation Strava a expiré ou a été retirée (il faut relier Strava de nouveau dans le profil)',
            $response->status() === 403 => 'Strava refuse l\'accès aux données',
            $response->serverError() => 'Strava ne répond pas correctement pour le moment',
            default => 'Strava a renvoyé une erreur',
        };

        return new self($reason, $detail);
    }
}
