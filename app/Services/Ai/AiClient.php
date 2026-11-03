<?php

namespace App\Services\Ai;

use App\Services\RetriesTransientErrors;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Appels à l'IA choisie par AI_PROVIDER (openai ou anthropic), partagés par les modules du hub.
 */
class AiClient
{
    use RetriesTransientErrors;

    public function provider(): string
    {
        $provider = config('ai.provider');
        if (! in_array($provider, ['openai', 'anthropic'], true)) {
            throw new RuntimeException('Fournisseur IA inconnu : '.$provider);
        }

        return $provider;
    }

    /** L'API Batch (moitié prix, réponse différée) n'est branchée que pour OpenAI. */
    public function supportsBatch(): bool
    {
        return $this->provider() === 'openai';
    }

    /**
     * Recherche web libre : le modèle cherche lui-même. Coûteux en tokens.
     *
     * @param  array<string, string>|null  $userLocation  position approximative (OpenAI : type, country, city, timezone)
     */
    public function searchWeb(string $prompt, ?array $userLocation = null): AiResult
    {
        if ($this->provider() === 'anthropic') {
            return $this->anthropic($prompt, (string) config('services.anthropic.model'), webSearch: true);
        }

        $model = (string) config('services.openai.model');
        $tool = ['type' => 'web_search'] + ($userLocation ? ['user_location' => $userLocation] : []);

        return $this->parseOpenAiBody($this->postOpenAi(['model' => $model, 'input' => $prompt, 'tools' => [$tool]]), $model);
    }

    /**
     * Extraction de données depuis un texte fourni : sans recherche web, avec le modèle économique,
     * et le schéma JSON imposé (OpenAI) ou ajouté à la fin du prompt (Claude).
     *
     * @param  string  $schemaName  nom court du schéma ([a-z_])
     * @param  array<string, mixed>  $schema  schéma JSON strict de l'objet attendu
     */
    public function extract(string $prompt, string $schemaName, array $schema): AiResult
    {
        if ($this->provider() === 'anthropic') {
            $prompt .= "\n\nRéponds uniquement par un objet JSON conforme à ce schéma :\n".json_encode($schema, JSON_UNESCAPED_UNICODE);

            return $this->anthropic($prompt, (string) config('services.anthropic.extract_model'));
        }

        $body = $this->extractionBody($prompt, $schemaName, $schema);

        return $this->parseOpenAiBody($this->postOpenAi($body), $body['model']);
    }

    /**
     * Corps de requête OpenAI /v1/responses pour une extraction (aussi utilisé dans les lots Batch).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function extractionBody(string $prompt, string $schemaName, array $schema): array
    {
        $body = [
            'model' => (string) config('services.openai.extract_model'),
            'input' => $prompt,
            'text' => ['format' => ['type' => 'json_schema', 'name' => $schemaName, 'schema' => $schema, 'strict' => true]],
        ];
        if ($reasoning = config('services.openai.extract_reasoning')) {
            $body['reasoning'] = ['effort' => $reasoning];
        }

        return $body;
    }

    /**
     * Lit une réponse OpenAI /v1/responses (appel direct ou ligne de résultat Batch).
     *
     * @param  array<string, mixed>  $body
     */
    public function parseOpenAiBody(array $body, string $model, bool $batch = false): AiResult
    {
        $content = collect($body['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $message) => $message['content'] ?? []);

        if ($refusal = $content->firstWhere('type', 'refusal')) {
            throw new RuntimeException('API OpenAI : refus du modèle ('.($refusal['refusal'] ?? '').')');
        }

        return new AiResult(
            text: $content->where('type', 'output_text')->pluck('text')->implode(''),
            model: (string) ($body['model'] ?? $model),
            inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            batch: $batch,
        );
    }

    /** Requête authentifiée vers OpenAI, avec réessais sur les erreurs passagères (429, 5xx, réseau). */
    public function openAiRequest(): PendingRequest
    {
        return Http::withToken((string) config('services.openai.key'))
            ->retry(3, fn (int $attempt) => $attempt * 5000, fn (\Throwable $e) => $this->isTransient($e), throw: false);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postOpenAi(array $body): array
    {
        $response = $this->openAiRequest()->timeout(160)->post('https://api.openai.com/v1/responses', $body);

        if ($response->failed()) {
            throw new RuntimeException('API OpenAI : HTTP '.$response->status().' '.$response->json('error.message'));
        }

        return $response->json();
    }

    private function anthropic(string $prompt, string $model, bool $webSearch = false): AiResult
    {
        $payload = [
            'model' => $model,
            'max_tokens' => 8000,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($webSearch) {
            $payload['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 8]];
        }

        /** @var Response $response */
        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(160)
            ->retry(3, fn (int $attempt) => $attempt * 5000, fn (\Throwable $e) => $this->isTransient($e), throw: false)
            ->post('https://api.anthropic.com/v1/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException('API Claude : HTTP '.$response->status().' '.$response->json('error.message'));
        }

        return new AiResult(
            text: collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode(''),
            model: (string) $response->json('model', $model),
            inputTokens: (int) $response->json('usage.input_tokens', 0),
            outputTokens: (int) $response->json('usage.output_tokens', 0),
        );
    }
}
