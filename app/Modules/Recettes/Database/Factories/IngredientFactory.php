<?php

namespace App\Modules\Recettes\Database\Factories;

use App\Modules\Recettes\Models\Ingredient;
use App\Modules\Recettes\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'position' => 0,
            'quantity' => fake()->numberBetween(1, 500),
            'unit' => 'g',
            'name' => fake()->word(),
            'note' => null,
            'emoji' => null,
        ];
    }
}
