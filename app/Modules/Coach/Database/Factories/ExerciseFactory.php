<?php

namespace App\Modules\Coach\Database\Factories;

use App\Models\User;
use App\Modules\Coach\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exercise>
 */
class ExerciseFactory extends Factory
{
    protected $model = Exercise::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'measure' => 'load_reps',
            'muscles' => ['quads'],
            'unilateral' => false,
        ];
    }
}
