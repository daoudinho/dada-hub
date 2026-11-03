<?php

namespace App\Modules\Recettes\Services;

use App\Modules\Recettes\Models\CartItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Liste de courses du panier : ingrédients de toutes les recettes, ramenés au nombre de
 * personnes choisi, et additionnés quand ils portent le même nom et une unité compatible
 * (les grammes avec les kilos, les centilitres avec les litres…).
 *
 * @phpstan-type ShoppingLine array{key: string, name: string, emoji: string|null, amount: string, recipes: array<int, string>}
 */
class ShoppingList
{
    /** Unités convertibles : [unité de base, facteur]. */
    private const CONVERTIBLE = [
        'mg' => ['g', 0.001], 'g' => ['g', 1], 'kg' => ['g', 1000],
        'ml' => ['ml', 1], 'cl' => ['ml', 10], 'dl' => ['ml', 100], 'l' => ['ml', 1000],
    ];

    /** Écritures courantes d'une même unité. */
    private const ALIASES = [
        'gr' => 'g', 'gramme' => 'g', 'grammes' => 'g',
        'kilo' => 'kg', 'kilos' => 'kg', 'kilogramme' => 'kg', 'kilogrammes' => 'kg',
        'litre' => 'l', 'litres' => 'l', 'millilitre' => 'ml', 'millilitres' => 'ml', 'centilitre' => 'cl', 'centilitres' => 'cl',
        'cas' => 'c. à soupe', 'cs' => 'c. à soupe', 'c. a soupe' => 'c. à soupe', 'cuillere a soupe' => 'c. à soupe', 'cuilleres a soupe' => 'c. à soupe',
        'cac' => 'c. à café', 'cc' => 'c. à café', 'c. a cafe' => 'c. à café', 'cuillere a cafe' => 'c. à café', 'cuilleres a cafe' => 'c. à café',
    ];

    /**
     * @param  Collection<int, CartItem>  $items  avec recipe.ingredients chargés
     * @return Collection<int, ShoppingLine>
     */
    public function build(Collection $items): Collection
    {
        $lines = [];

        foreach ($items as $item) {
            $recipe = $item->recipe;
            $ratio = $item->servings / max(1, $recipe->servings);

            foreach ($recipe->ingredients as $ingredient) {
                [$unit, $factor] = $this->baseUnit($ingredient->unit);
                $key = $this->nameKey($ingredient->name).'|'.($ingredient->quantity === null ? 'text:'.Str::lower((string) $ingredient->unit) : $unit);

                $lines[$key] ??= ['name' => $ingredient->name, 'emoji' => $ingredient->emoji, 'unit' => $unit, 'text' => $ingredient->unit, 'quantity' => null, 'recipes' => []];
                $lines[$key]['emoji'] ??= $ingredient->emoji;
                $lines[$key]['recipes'][$recipe->id] = $recipe->name;

                if ($ingredient->quantity !== null) {
                    $lines[$key]['quantity'] = ($lines[$key]['quantity'] ?? 0) + $ingredient->quantity * $ratio * $factor;
                }
            }
        }

        return collect($lines)
            ->map(fn (array $line, string $key) => [
                'key' => $key,
                'name' => $line['name'],
                'emoji' => $line['emoji'],
                'amount' => $line['quantity'] === null ? (string) $line['text'] : $this->amount($line['quantity'], $line['unit']),
                'recipes' => array_values($line['recipes']),
            ])
            ->sortBy(fn (array $line) => Str::lower(Str::ascii($line['name'])))
            ->values();
    }

    /**
     * Unité de base et facteur de conversion : « 50 cl » → ['ml', 10] ; unité inconnue → [elle-même, 1].
     *
     * @return array{0: string, 1: float}
     */
    private function baseUnit(?string $unit): array
    {
        $unit = Str::lower(trim((string) $unit));
        $unit = self::ALIASES[Str::ascii($unit)] ?? self::ALIASES[$unit] ?? $unit;

        return self::CONVERTIBLE[$unit] ?? [$unit, 1.0];
    }

    /** Quantité totale dans l'unité la plus lisible : 1500 g → « 1,5 kg ». */
    private function amount(float $quantity, string $unit): string
    {
        return match (true) {
            $unit === 'g' && $quantity >= 1000 => Quantity::label($quantity / 1000, 'kg'),
            $unit === 'ml' && $quantity >= 1000 => Quantity::label($quantity / 1000, 'l'),
            default => Quantity::label($quantity, $unit),
        };
    }

    /** Clé de regroupement : sans accents ni majuscules, et sans pluriel (« Tomates » = « tomate »). */
    private function nameKey(string $name): string
    {
        return collect(preg_split('/[\s\']+/', Str::lower(Str::ascii(trim($name)))))
            ->filter()
            ->map(fn (string $word) => strlen($word) > 3 ? rtrim($word, 'sx') : $word)
            ->implode(' ');
    }
}
