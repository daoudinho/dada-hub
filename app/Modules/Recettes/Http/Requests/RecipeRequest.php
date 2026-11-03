<?php

namespace App\Modules\Recettes\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Formulaire de saisie manuelle d'une recette (création et modification).
 */
class RecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Les lignes vides du formulaire (ingrédients, étapes) sont ignorées ; « 0,5 » vaut « 0.5 ».
     */
    protected function prepareForValidation(): void
    {
        $ingredients = collect((array) $this->input('ingredients', []))
            ->filter(fn ($row) => is_array($row) && collect($row)->filter(fn ($value) => trim((string) $value) !== '')->isNotEmpty())
            ->map(fn (array $row) => ['quantity' => str_replace(',', '.', trim((string) ($row['quantity'] ?? '')))] + $row) // validée ici, convertie par RecipeWriter
            ->values()
            ->all();

        $steps = collect((array) $this->input('steps', []))
            ->filter(fn ($step) => is_string($step) && trim($step) !== '')
            ->values()
            ->all();

        $this->merge(['ingredients' => $ingredients, 'steps' => $steps, 'enabled' => $this->boolean('enabled')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'servings' => ['required', 'integer', 'min:1', 'max:100'],
            'total_minutes' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'source_url' => ['nullable', 'url:http,https', 'max:500'],
            'enabled' => ['boolean'],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', 'exists:recipe_tags,id'],
            'ingredients' => ['array', 'max:100'],
            'ingredients.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:30'],
            'ingredients.*.name' => ['required', 'string', 'max:120'],
            'ingredients.*.note' => ['nullable', 'string', 'max:120'],
            'ingredients.*.emoji' => ['nullable', 'string', 'max:16'],
            'steps' => ['array', 'max:50'],
            'steps.*' => ['string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('recettes.image.max_upload_kb')],
            'remove_image' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'servings' => 'nombre de personnes',
            'total_minutes' => 'temps total',
            'source_url' => 'lien de la recette',
            'ingredients.*.name' => 'nom de l\'ingrédient',
            'ingredients.*.quantity' => 'quantité',
            'steps.*' => 'étape',
        ];
    }
}
