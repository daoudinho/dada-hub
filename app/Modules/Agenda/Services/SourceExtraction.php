<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Models\Source;

/**
 * Applique le résultat d'une extraction IA à une source : import des événements,
 * ménage des événements disparus, état de la source et consommation du scan.
 */
class SourceExtraction
{
    public function __construct(private EventImporter $importer, private Prompts $prompts) {}

    /**
     * @param  array<int, string>  $categories
     * @return int nombre d'événements ajoutés ou mis à jour
     */
    public function apply(int $scanId, Source $source, string $contentHash, array $categories, AiResult $ai): int
    {
        Scan::addUsage($scanId, $ai);

        $ids = $this->importer->import($this->importer->parse($ai->text), $categories, $source);

        // Aucun événement renvoyé : peut-être une extraction ratée, on ne supprime rien.
        if ($ids !== []) {
            $this->importer->prune($source, $ids, $this->prompts->until());
        }

        $source->markScanned('ok', [
            'content_hash' => $contentHash,
            'last_found' => count($ids),
            'last_input_tokens' => $ai->inputTokens,
            'last_output_tokens' => $ai->outputTokens,
        ]);

        return count($ids);
    }
}
