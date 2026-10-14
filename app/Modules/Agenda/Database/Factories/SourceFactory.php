<?php

namespace App\Modules\Agenda\Database\Factories;

use App\Modules\Agenda\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'url' => fake()->unique()->url(),
            'category' => 'musique',
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
