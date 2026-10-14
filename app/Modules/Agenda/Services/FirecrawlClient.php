<?php

namespace App\Modules\Agenda\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Récupère une page web en Markdown via l'API Firecrawl (https://docs.firecrawl.dev).
 */
class FirecrawlClient
{
    use RetriesTransientErrors;

    private const MONTHS = 'janv?(?:ier)?|january|enero|gener|f[ée]v(?:r(?:ier)?)?|feb(?:ruary|rero|rer)?|mars|mar(?:ch|zo|ç)?|avr(?:il)?|apr(?:il)?|abril|mai|may(?:o)?|maig|juin|june?|junio|juny|juil(?:let)?|july?|julio|juliol|ao[uû]t|aug(?:ust)?|agost(?:o)?|sept?(?:embre|ember|iembre)?|setembre|oct(?:obre|ober|ubre)?|nov(?:embre|ember|iembre)?|d[ée]c(?:embre|ember)?|diciembre|desembre';

    /**
     * Récupère la page, la nettoie et calcule son empreinte.
     */
    public function scrape(string $url): ScrapeResult
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
                'location' => ['country' => 'ES'],
            ]);

        if ($response->failed() || $response->json('success') === false) {
            throw new RuntimeException('Firecrawl : HTTP '.$response->status().' '.$response->json('error'));
        }

        $status = (int) $response->json('data.metadata.statusCode', 200);
        if ($status >= 400) {
            throw new RuntimeException('Firecrawl : la page a répondu HTTP '.$status);
        }

        $markdown = $this->clean((string) $response->json('data.markdown', ''));

        return new ScrapeResult(
            markdown: $markdown,
            fingerprint: $this->fingerprint($markdown),
            credits: 1 + (int) $response->json('data.metadata.provider.creditsCost', 0),
        );
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

    /**
     * Empreinte de la programmation : seules les lignes contenant une date ou une heure, avec la
     * ligne qui les précède (souvent le titre). Un compteur, une bannière ou un paramètre d'URL
     * qui change ne déclenchent donc pas de nouvel appel à l'IA. Repli sur toute la page si elle
     * contient trop peu de dates.
     */
    public function fingerprint(string $markdown): string
    {
        $lines = array_values(array_filter(array_map(
            fn (string $line) => $this->normalize($line),
            explode("\n", $markdown),
        )));

        $kept = [];
        foreach ($lines as $i => $line) {
            if ($this->hasDate($line)) {
                if ($i > 0) {
                    $kept[$i - 1] = $lines[$i - 1];
                }
                $kept[$i] = $line;
            }
        }

        return sha1(count($kept) >= 3 ? implode("\n", $kept) : implode("\n", $lines));
    }

    private function hasDate(string $line): bool
    {
        $months = self::MONTHS;

        return (bool) preg_match('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}[\/.]\d{1,2}(?:[\/.]\d{2,4})?\b|\b\d{1,2}[:h]\d{2}\b/u', $line)
            || (bool) preg_match('/\b\d{1,2}(?:er|st|nd|rd|th)?\.?\s+(?:de\s+|d\')?(?:'.$months.')\b|\b(?:'.$months.')\.?\s+\d{1,2}\b/iu', $line);
    }

    /** Minuscules, sans URL ni espaces superflus. */
    private function normalize(string $line): string
    {
        $line = preg_replace('/\]\([^)]*\)/', ']', $line);
        $line = preg_replace('#https?://\S+#', '', $line);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($line)));
    }
}
