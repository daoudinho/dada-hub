<?php

namespace App\Modules\Coach\Services\GoogleHealth;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Google Health refuse ou échoue (autorisation retirée, compte non lié, limite de requêtes, panne…) : une explication
 * lisible pour l'athlète, et le détail technique à transmettre à celui qui gère le site.
 */
class GoogleHealthUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $detail,
        public readonly bool $rateLimited = false,
        public readonly bool $reconnect = false,
    ) {
        parent::__construct('API Google Health : '.$detail);
    }

    public static function fromResponse(Response $response, string $path): self
    {
        $status = (string) $response->json('error.status');
        $detail = 'HTTP '.$response->status().' sur '.$path.' — '.mb_strimwidth((string) ($response->json('error.message') ?? trim($response->body()) ?: 'sans message'), 0, 300, '…');

        $reason = match (true) {
            $response->status() === 429 => 'Google Health limite le nombre de demandes pour le moment (réessayer un peu plus tard)',
            str_contains($response->body(), 'ACCOUNT_NOT_LINKED') => 'ton compte Google n\'est pas encore lié à l\'application Google Health '
                .'(ouvre l\'app Google Health sur ton téléphone et connecte-toi avec ce compte Google)',
            $response->status() === 401 => 'l\'autorisation Google a expiré ou a été retirée (il faut relier Google Health de nouveau dans le profil)',
            $response->status() === 403 => 'Google refuse l\'accès à ces données'.($status ? ' ('.$status.')' : ''),
            $response->serverError() => 'Google Health ne répond pas correctement pour le moment',
            default => 'Google Health a renvoyé une erreur',
        };

        return new self($reason, $detail, $response->status() === 429, $response->status() === 401);
    }

    /** Le jeton de renouvellement n'est plus accepté (révoqué, ou expiré au bout de 7 jours en mode test). */
    public static function refreshRefused(Response $response): self
    {
        return new self(
            'l\'autorisation Google a expiré (au bout de 7 jours tant que l\'application Google est en mode test) : relie Google Health de nouveau dans le profil',
            'HTTP '.$response->status().' au renouvellement du jeton — '.($response->json('error') ?? 'sans message'),
            reconnect: true,
        );
    }
}
