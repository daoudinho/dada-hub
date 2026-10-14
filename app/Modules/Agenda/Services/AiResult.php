<?php

namespace App\Modules\Agenda\Services;

/**
 * Réponse d'un appel à l'IA et sa consommation.
 */
final readonly class AiResult
{
    public function __construct(
        public string $text,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public bool $batch = false,
    ) {}

    /**
     * Coût estimé en dollars, ou null si le prix du modèle n'est pas renseigné (config agenda.prices).
     */
    public function cost(): ?float
    {
        $prices = config('agenda.prices.'.$this->model);
        if (! is_array($prices)) {
            return null;
        }

        $cost = ($this->inputTokens * $prices[0] + $this->outputTokens * $prices[1]) / 1_000_000;

        return $this->batch ? $cost * (float) config('agenda.batch_price_factor') : $cost;
    }
}
