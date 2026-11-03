<?php

namespace App\Modules\Recettes\Services;

use App\Modules\Recettes\Models\Recipe;
use App\Modules\Recettes\Models\RecipeImage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre une recette, ses ingrédients, ses étapes, ses étiquettes et son image.
 * Point de passage commun du formulaire et de l'import par IA.
 *
 * @phpstan-type IngredientData array{quantity?: float|int|string|null, unit?: string|null, name: string, note?: string|null, emoji?: string|null}
 * @phpstan-type RecipeData array{
 *     name?: string, description?: string|null, servings?: int, total_minutes?: int|null, source_url?: string|null,
 *     enabled?: bool, import_status?: string|null, import_error?: string|null,
 *     steps?: array<int, string|null>, ingredients?: array<int, IngredientData>, tag_ids?: array<int, int>
 * }
 */
class RecipeWriter
{
    private const ATTRIBUTES = ['name', 'description', 'servings', 'total_minutes', 'source_url', 'enabled', 'import_status', 'import_error'];

    public function __construct(private ImageProcessor $images) {}

    /**
     * Seules les clés présentes sont modifiées ; « ingredients » et « steps » remplacent la liste entière.
     *
     * @param  RecipeData  $data
     */
    public function save(Recipe $recipe, array $data): Recipe
    {
        DB::transaction(function () use ($recipe, $data) {
            $recipe->fill(Arr::only($data, self::ATTRIBUTES));

            if (array_key_exists('steps', $data)) {
                $recipe->steps = collect($data['steps'])->map(fn ($step) => trim((string) $step))->filter()->values()->all();
            }

            $recipe->save();

            if (array_key_exists('ingredients', $data)) {
                $recipe->ingredients()->delete();
                $recipe->ingredients()->createMany(
                    collect($data['ingredients'])->map($this->normalizeIngredient(...))->filter()->values()
                        ->map(fn (array $ingredient, int $position) => $ingredient + ['position' => $position])
                        ->all()
                );
            }

            if (array_key_exists('tag_ids', $data)) {
                $recipe->tags()->sync($data['tag_ids']);
            }
        });

        return $recipe->unsetRelation('ingredients')->unsetRelation('tags');
    }

    /** Remplace l'image de la recette par celle fournie (réduite et réencodée). */
    public function attachImage(Recipe $recipe, string $binary): void
    {
        ['mime' => $mime, 'binary' => $encoded] = $this->images->process($binary);

        RecipeImage::updateOrCreate(['recipe_id' => $recipe->id], ['mime' => $mime, 'binary' => $encoded]);
        $recipe->update(['image_hash' => sha1($encoded)]);
    }

    public function removeImage(Recipe $recipe): void
    {
        $recipe->image()->delete();
        $recipe->update(['image_hash' => null]);
    }

    /**
     * @param  IngredientData  $ingredient
     * @return array{quantity: float|null, unit: string|null, name: string, note: string|null, emoji: string|null}|null
     */
    private function normalizeIngredient(array $ingredient): ?array
    {
        $text = fn (string $key, int $max) => mb_substr(trim((string) ($ingredient[$key] ?? '')), 0, $max) ?: null;

        $name = $text('name', 120);
        if ($name === null) {
            return null;
        }

        return [
            'quantity' => Quantity::parse($ingredient['quantity'] ?? null),
            'unit' => $text('unit', 30),
            'name' => $name,
            'note' => $text('note', 120),
            'emoji' => $text('emoji', 16),
        ];
    }
}
