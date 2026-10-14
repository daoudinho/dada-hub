<?php

namespace App\Modules\Agenda\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Appels à l'IA choisie par AGENDA_AI_PROVIDER (openai ou anthropic).
 */
class AiClient
{
    /**
     * Recherche web libre (mode « web ») : le modèle cherche lui-même. Coûteux en tokens.
     */
    public function searchWeb(string $prompt): string
    {
        return match ($this->provider()) {
            'openai' => $this->openAi($prompt, (string) config('services.openai.model'), webSearch: true),
            'anthropic' => $this->anthropic($prompt, (string) config('services.anthropic.model'), webSearch: true),
        };
    }

    /**
     * Extraction d'événements depuis un texte fourni (mode « sources ») : sans recherche web,
     * avec le modèle économique, et un schéma JSON imposé quand le fournisseur le permet.
     *
     * @param  array<int, string>  $categories
     */
    public function extractEvents(string $prompt, array $categories): string
    {
        return match ($this->provider()) {
            'openai' => $this->openAi(
                $prompt,
                (string) config('services.openai.extract_model'),
                schema: $this->eventsSchema($categories),
                reasoning: config('services.openai.extract_reasoning') ?: null,
            ),
            'anthropic' => $this->anthropic($prompt, (string) config('services.anthropic.extract_model')),
        };
    }

    private function provider(): string
    {
        $provider = config('services.agenda.provider');
        if (! in_array($provider, ['openai', 'anthropic'], true)) {
            throw new RuntimeException('Fournisseur IA inconnu : '.$provider);
        }

        return $provider;
    }

    /**
     * @param  array<string, mixed>|null  $schema
     */
    private function openAi(string $prompt, string $model, bool $webSearch = false, ?array $schema = null, ?string $reasoning = null): string
    {
        $payload = ['model' => $model, 'input' => $prompt];
        if ($reasoning !== null) {
            $payload['reasoning'] = ['effort' => $reasoning];
        }
        if ($webSearch) {
            $payload['tools'] = [[
                'type' => 'web_search',
                'user_location' => ['type' => 'approximate', 'country' => 'ES', 'city' => 'Barcelona', 'timezone' => 'Europe/Madrid'],
            ]];
        }
        if ($schema !== null) {
            $payload['text'] = ['format' => ['type' => 'json_schema', 'name' => 'agenda_events', 'schema' => $schema, 'strict' => true]];
        }

        $response = Http::withToken((string) config('services.openai.key'))
            ->timeout(160)
            ->post('https://api.openai.com/v1/responses', $payload);

        if ($response->failed()) {
            throw new RuntimeException('API OpenAI : HTTP '.$response->status().' '.$response->json('error.message'));
        }

        $content = collect($response->json('output', []))
            ->where('type', 'message')
            ->flatMap(fn (array $message) => $message['content'] ?? []);

        if ($refusal = $content->firstWhere('type', 'refusal')) {
            throw new RuntimeException('API OpenAI : refus du modèle ('.($refusal['refusal'] ?? '').')');
        }

        return $content->where('type', 'output_text')->pluck('text')->implode('');
    }

    private function anthropic(string $prompt, string $model, bool $webSearch = false): string
    {
        $payload = [
            'model' => $model,
            'max_tokens' => 8000,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($webSearch) {
            $payload['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 8]];
        }

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(160)
            ->post('https://api.anthropic.com/v1/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException('API Claude : HTTP '.$response->status().' '.$response->json('error.message'));
        }

        return collect($response->json('content', []))
            ->where('type', 'text')->pluck('text')->implode('');
    }

    /**
     * Schéma strict : {"events": [{title, start, end, time, place, url, category}]}.
     *
     * @param  array<int, string>  $categories
     * @return array<string, mixed>
     */
    private function eventsSchema(array $categories): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['events'],
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'start', 'end', 'time', 'place', 'url', 'category'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'start' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                            'end' => $nullableString + ['description' => 'YYYY-MM-DD'],
                            'time' => $nullableString + ['description' => 'HH:MM'],
                            'place' => $nullableString,
                            'url' => $nullableString,
                            'category' => ['type' => 'string', 'enum' => array_values($categories)],
                        ],
                    ],
                ],
            ],
        ];
    }
}
