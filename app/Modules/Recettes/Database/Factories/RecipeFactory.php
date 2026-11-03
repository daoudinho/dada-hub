<?php

namespace App\Modules\Recettes\Database\Factories;

use App\Modules\Recettes\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    protected $model = Recipe::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->words(3, true)),
            'description' => fake()->sentence(),
            'servings' => 2,
            'total_minutes' => fake()->numberBetween(10, 90),
            'steps' => [fake()->sentence(), fake()->sentence()],
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }

    public function importing(string $url = 'https://cuisine.test/recette'): static
    {
        return $this->state(['source_url' => $url, 'import_status' => 'pending', 'description' => null, 'total_minutes' => null, 'steps' => []]);
    }
}
