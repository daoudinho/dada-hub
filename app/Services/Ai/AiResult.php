<?php

namespace App\Services\Ai;

use RuntimeException;

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
     * Coût estimé en dollars, ou null si le prix du modèle n'est pas renseigné (config ai.prices).
     */
    public function cost(): ?float
    {
        $prices = config('ai.prices.'.$this->model);
        if (! is_array($prices)) {
            return null;
        }

        $cost = ($this->inputTokens * $prices[0] + $this->outputTokens * $prices[1]) / 1_000_000;

        return $this->batch ? $cost * (float) config('ai.batch_price_factor') : $cost;
    }

    /**
     * Objet JSON de la réponse, même entouré de texte ou d'un bloc de code (réponses sans schéma imposé).
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode(trim($this->text), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($this->text, '{');
        $end = strrpos($this->text, '}');
        $decoded = $start === false || $end === false ? null : json_decode(substr($this->text, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Réponse de l\'IA sans objet JSON lisible.');
        }

        return $decoded;
    }
}
