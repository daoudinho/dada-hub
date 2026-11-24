<?php

namespace App\Modules\Coach\Services;

use App\Services\RetriesTransientErrors;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Appel à l'API Messages de Claude pour le coach : conversation à plusieurs tours, avec outils.
 *
 * Appels HTTP directs, comme App\Services\Ai\AiClient (pas de SDK dans le projet).
 */
class ClaudeClient
{
    use RetriesTransientErrors;

    /**
     * @param  array<int, array<string, mixed>>  $messages  historique complet, renvoyé sans modification
     * @param  array<int, array<string, mixed>>  $tools
     * @return array{content: array<int, array<string, mixed>>, stop_reason: string, model: string, input_tokens: int, output_tokens: int}
     */
    public function send(string $system, array $messages, array $tools): array
    {
        $payload = [
            'model' => (string) config('coach.ai.model'),
            'max_tokens' => (int) config('coach.ai.max_tokens'),
            // Consignes et outils fixes : mis en cache. L'historique l'est aussi (cache_control global).
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'cache_control' => ['type' => 'ephemeral'],
            'tools' => $tools,
            'messages' => $messages,
            // Les blocs de réflexion sont liés aux consignes et aux outils qui les ont produits : quand on les fait évoluer,
            // l'API écarte les anciens blocs au lieu de refuser toute la conversation.
            'thinking' => ['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'drop_block']],
            'output_config' => ['effort' => (string) config('coach.ai.effort')],
            // Si un filtre de sécurité refuse la demande, l'API la repasse sur un autre modèle.
            'fallbacks' => 'default',
        ];

        /** @var Response $response */
        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
            'anthropic-beta' => 'server-side-fallback-2026-07-01,thinking-binding-controls-2026-08-01',
        ])
            ->timeout(170)
            ->retry(3, fn (int $attempt) => $attempt * 5000, fn (\Throwable $e) => $this->isTransient($e), throw: false)
            ->post('https://api.anthropic.com/v1/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException('API Claude : HTTP '.$response->status().' '.$response->json('error.message'));
        }

        return [
            'content' => $response->json('content', []),
            'stop_reason' => (string) $response->json('stop_reason', 'end_turn'),
            'model' => (string) $response->json('model', $payload['model']),
            'input_tokens' => (int) $response->json('usage.input_tokens', 0) + (int) $response->json('usage.cache_read_input_tokens', 0) + (int) $response->json('usage.cache_creation_input_tokens', 0),
            'output_tokens' => (int) $response->json('usage.output_tokens', 0),
        ];
    }
}
