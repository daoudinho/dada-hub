<?php

namespace App\Modules\Recettes\Services;

use Illuminate\Support\Number;

/**
 * Saisie et affichage des quantités d'ingrédients : « ½ », « 1¾ », « 0,15 », « 400 ».
 */
class Quantity
{
    /**
     * Fractions affichées en glyphe (aussi lues par le JavaScript de la fiche recette).
     *
     * @var array<int, array{0: float, 1: string}>
     */
    public const FRACTIONS = [[0.25, '¼'], [1 / 3, '⅓'], [0.5, '½'], [2 / 3, '⅔'], [0.75, '¾']];

    /** Quantité suivie de l'unité ; seulement l'unité (« quelques feuilles ») pour une quantité non chiffrée. */
    public static function label(?float $quantity, ?string $unit): string
    {
        if ($quantity === null) {
            return (string) $unit;
        }

        return trim(self::format($quantity).' '.$unit);
    }

    public static function format(float $value): string
    {
        if ($value >= 100) {
            return (string) (int) round($value);
        }

        $whole = (int) floor($value);
        $rest = $value - $whole;

        if ($value < 10 && $rest > 0.01) {
            foreach (self::FRACTIONS as [$fraction, $glyph]) {
                if (abs($rest - $fraction) < 0.02) {
                    return ($whole > 0 ? $whole : '').$glyph;
                }
            }
        }

        if ($rest > 0.99) {
            return (string) ($whole + 1);
        }

        return (string) Number::format($value, maxPrecision: 2, locale: 'fr');
    }

    /** Quantité saisie (« 0,5 », « 2 ») en nombre ; null si vide, non numérique ou nulle. */
    public static function parse(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));

        return is_numeric($value) && (float) $value > 0 ? round((float) $value, 3) : null;
    }
}
