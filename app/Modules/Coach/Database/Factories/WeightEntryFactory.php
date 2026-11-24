<?php

namespace App\Modules\Coach\Database\Factories;

use App\Models\User;
use App\Modules\Coach\Models\WeightEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeightEntry>
 */
class WeightEntryFactory extends Factory
{
    protected $model = WeightEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'measured_on' => fake()->unique()->dateTimeBetween('-1 year')->format('Y-m-d'),
            'weight_kg' => fake()->randomFloat(1, 55, 90),
        ];
    }
}
