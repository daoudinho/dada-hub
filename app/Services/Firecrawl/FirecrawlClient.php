<?php

namespace App\Services\Firecrawl;

use App\Services\RetriesTransientErrors;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Récupère une page web en Markdown via l'API Firecrawl (https://docs.firecrawl.dev).
 */
class FirecrawlClient
{
    use RetriesTransientErrors;

    /**
     * Récupère la page et la nettoie.
     *
     * @param  string|null  $country  pays d'où la page est consultée (code ISO, ex. « ES »)
     */
    public function scrape(string $url, ?string $country = null): ScrapeResult
    {
        $key = (string) config('services.firecrawl.key');
        if ($key === '') {
            throw new RuntimeException('FIRECRAWL_API_KEY manquante dans .env.');
        }

        $response = Http::withToken($key)
            ->timeout(90)
            ->retry(3, fn (int $attempt) => $attempt * 3000, fn (\Throwable $e) => $this->isTransient($e), throw: false)
            ->post('https://api.firecrawl.dev/v2/scrape', [
                'url' => $url,
                'formats' => ['markdown'],
                'onlyMainContent' => true,
                'maxAge' => (int) config('services.firecrawl.max_age'),
                'timeout' => 60000,
            ] + ($country ? ['location' => ['country' => $country]] : []));

        if ($response->failed() || $response->json('success') === false) {
            throw new RuntimeException('Firecrawl : HTTP '.$response->status().' '.$response->json('error'));
        }

        $status = (int) $response->json('data.metadata.statusCode', 200);
        if ($status >= 400) {
            throw new RuntimeException('Firecrawl : la page a répondu HTTP '.$status);
        }

        $markdown = (string) $response->json('data.markdown', '');

        return new ScrapeResult(
            markdown: $this->clean($markdown),
            credits: 1 + (int) $response->json('data.metadata.provider.creditsCost', 0),
            imageUrl: $this->mainImage($response->json('data.metadata', []), $markdown),
        );
    }

    /**
     * Retire ce qui ne sert pas à l'extraction (images, liens vides, espaces) et limite la taille.
     */
    private function clean(string $markdown): string
    {
        $markdown = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $markdown);
        $markdown = preg_replace('/\[\s*\]\([^)]*\)/', '', $markdown);
        $markdown = preg_replace('/[ \t]+$/m', '', $markdown);
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

        return mb_substr(trim($markdown), 0, (int) config('services.firecrawl.max_chars'));
    }

    /**
     * Image principale : celle des réseaux sociaux (og:image), sinon la première image du contenu.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function mainImage(array $metadata, string $markdown): ?string
    {
        $candidates = [$metadata['ogImage'] ?? null, $metadata['og:image'] ?? null, $metadata['twitter:image'] ?? null];
        if (preg_match('/!\[[^\]]*\]\((https?:\/\/[^)\s]+)/', $markdown, $match)) {
            $candidates[] = $match[1];
        }

        foreach ($candidates as $candidate) {
            $candidate = is_array($candidate) ? ($candidate[0] ?? null) : $candidate;
            if (is_string($candidate) && preg_match('#^https?://#i', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
