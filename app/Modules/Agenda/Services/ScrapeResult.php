<?php

namespace App\Modules\Agenda\Services;

/**
 * Page récupérée par Firecrawl : contenu nettoyé, empreinte et crédits consommés.
 */
final readonly class ScrapeResult
{
    public function __construct(
        public string $markdown,
        public string $fingerprint,
        public int $credits = 1,
    ) {}
}
