<?php

namespace App\Modules\Agenda\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Récupère une page web en Markdown via l'API Firecrawl (https://docs.firecrawl.dev).
 */
class FirecrawlClient
{
    /**
     * Renvoie le contenu principal de la page, nettoyé pour l'IA.
     */
    public function scrape(string $url): string
    {
        $key = (string) config('services.firecrawl.key');
        if ($key === '') {
            throw new RuntimeException('FIRECRAWL_API_KEY manquante dans .env.');
        }

        $response = Http::withToken($key)
            ->timeout(90)
            ->post('https://api.firecrawl.dev/v2/scrape', [
                'url' => $url,
                'formats' => ['markdown'],
                'onlyMainContent' => true,
                'maxAge' => (int) config('services.firecrawl.max_age'),
                'timeout' => 60000,
                'location' => ['country' => 'ES'],
            ]);

        if ($response->failed() || $response->json('success') === false) {
            throw new RuntimeException('Firecrawl : HTTP '.$response->status().' '.$response->json('error'));
        }

        $status = (int) $response->json('data.metadata.statusCode', 200);
        if ($status >= 400) {
            throw new RuntimeException('Firecrawl : la page a répondu HTTP '.$status);
        }

        return $this->clean((string) $response->json('data.markdown', ''));
    }

    /**
     * Retire ce qui ne sert pas à l'extraction (images, liens vides, espaces) et limite la taille.
     */
    public function clean(string $markdown): string
    {
        $markdown = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $markdown);
        $markdown = preg_replace('/\[\s*\]\([^)]*\)/', '', $markdown);
        $markdown = preg_replace('/[ \t]+$/m', '', $markdown);
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

        return mb_substr(trim($markdown), 0, (int) config('services.firecrawl.max_chars'));
    }
}
