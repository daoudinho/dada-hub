<?php

namespace App\Services\Firecrawl;

/**
 * Page récupérée par Firecrawl : contenu nettoyé, image principale et crédits consommés.
 */
final readonly class ScrapeResult
{
    public function __construct(
        public string $markdown,
        public int $credits = 1,
        public ?string $imageUrl = null,
    ) {}
}
