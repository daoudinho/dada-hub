<?php

namespace App\Modules\Coach\Database\Factories;

use App\Models\User;
use App\Modules\Coach\Models\Workout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workout>
 */
class WorkoutFactory extends Factory
{
    protected $model = Workout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'performed_on' => fake()->dateTimeBetween('-2 months')->format('Y-m-d'),
            'duration_minutes' => 50,
            'rpe' => 7,
            'source' => 'form',
            'total_load_kg' => 0,
        ];
    }
}
