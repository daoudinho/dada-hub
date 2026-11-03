<?php

namespace App\Modules\Recettes\Services;

use App\Modules\Recettes\Models\Tag;
use App\Services\Ai\AiResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Extraction d'une recette par l'IA depuis le contenu d'une page web : prompt,
 * schéma JSON imposé, et conversion de la réponse au format de RecipeWriter.
 *
 * @phpstan-import-type RecipeData from RecipeWriter
 */
class RecipeExtraction
{
    public const SCHEMA_NAME = 'recipe';

    /**
     * @param  Collection<int, Tag>  $tags  étiquettes existantes, seules autorisées
     */
    public function prompt(string $url, string $markdown, Collection $tags): string
    {
        $tagList = $tags->isEmpty() ? '(aucune : renvoie une liste vide)' : $tags->map(fn (Tag $tag) => '- '.$tag->name)->implode("\n");

        return <<<TXT
Voici le contenu d'une page web ({$url}). Extrais-en la recette de cuisine principale.

Réponds en JSON :
{"found": true, "description": "...", "servings": 4, "total_minutes": 45,
 "ingredients": [{"quantity": 200, "unit": "g", "name": "farine", "note": null, "emoji": "🌾"}],
 "steps": ["..."], "tags": ["..."]}

Règles :
- Tout en français : traduis si la page est dans une autre langue.
- found : false si la page ne contient pas de recette (les autres champs sont alors vides ou null).
- description : une ou deux phrases qui donnent envie, sans inventer, ou null.
- servings : nombre de personnes (ou de parts), null s'il n'est pas indiqué.
- total_minutes : temps total en minutes (préparation + cuisson + repos), null s'il n'est pas indiqué.
- ingredients, dans l'ordre de la page :
  - quantity : nombre (« 1/2 » → 0.5, « 2 à 3 » → 2), null si non chiffré (« sel, poivre », « quelques feuilles »).
  - unit : g, kg, ml, cl, l, c. à soupe, c. à café, pincée, gousse, tranche, botte… ; null pour un nombre de pièces (« 4 tomates ») ;
    pour une quantité non chiffrée, la formulation à afficher (« quelques feuilles », « un filet »).
  - name : l'ingrédient seul, court, en minuscules (« tomates », « huile d'olive »).
  - note : précision éventuelle (« bien mûres », « en dés »), sinon null.
  - emoji : un emoji qui représente l'ingrédient, sinon null.
- steps : étapes de préparation, une phrase ou un court paragraphe chacune, sans numéro.
- tags : uniquement parmi les étiquettes ci-dessous, celles qui conviennent clairement à la recette :
{$tagList}

Contenu de la page :
---
{$markdown}
TXT;
    }

    /**
     * @param  Collection<int, Tag>  $tags
     * @return array<string, mixed>
     */
    public function schema(Collection $tags): array
    {
        $nullable = fn (string $type) => ['type' => [$type, 'null']];
        $tagItem = $tags->isEmpty() ? ['type' => 'string'] : ['type' => 'string', 'enum' => $tags->pluck('name')->values()->all()];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['found', 'description', 'servings', 'total_minutes', 'ingredients', 'steps', 'tags'],
            'properties' => [
                'found' => ['type' => 'boolean'],
                'description' => $nullable('string'),
                'servings' => $nullable('integer'),
                'total_minutes' => $nullable('integer'),
                'ingredients' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['quantity', 'unit', 'name', 'note', 'emoji'],
                        'properties' => [
                            'quantity' => $nullable('number'),
                            'unit' => $nullable('string'),
                            'name' => ['type' => 'string'],
                            'note' => $nullable('string'),
                            'emoji' => $nullable('string'),
                        ],
                    ],
                ],
                'steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tags' => ['type' => 'array', 'items' => $tagItem],
            ],
        ];
    }

    /**
     * Données de la recette, prêtes pour RecipeWriter::save() (sauf les étiquettes, renvoyées à part).
     *
     * @param  Collection<int, Tag>  $tags
     * @return array{recipe: RecipeData, tag_ids: array<int, int>}
     */
    public function parse(AiResult $result, Collection $tags): array
    {
        $data = $result->json();
        $ingredients = array_values(array_filter((array) ($data['ingredients'] ?? []), 'is_array'));

        if (($data['found'] ?? true) === false || $ingredients === []) {
            throw new RuntimeException('Aucune recette trouvée sur cette page.');
        }

        $recipe = [
            'description' => $this->text($data['description'] ?? null, 2000),
            'ingredients' => $ingredients,
            'steps' => array_map(fn ($step) => (string) $step, array_filter((array) ($data['steps'] ?? []), 'is_scalar')),
        ];

        $servings = (int) ($data['servings'] ?? 0);
        if ($servings > 0) {
            $recipe['servings'] = min($servings, 100);
        }

        $minutes = (int) ($data['total_minutes'] ?? 0);
        $recipe['total_minutes'] = $minutes > 0 ? min($minutes, 10000) : null;

        $wanted = collect((array) ($data['tags'] ?? []))->filter(fn ($name) => is_string($name))->map(fn (string $name) => Str::lower(trim($name)));

        return [
            'recipe' => $recipe,
            'tag_ids' => $tags->filter(fn (Tag $tag) => $wanted->contains(Str::lower($tag->name)))->pluck('id')->values()->all(),
        ];
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}
