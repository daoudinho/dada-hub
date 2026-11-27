<?php

namespace App\Modules\Coach\Database\Factories;

use App\Models\User;
use App\Modules\Coach\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    protected $model = Equipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->randomElement(['Presse à cuisses', 'Poulie basse', 'Banc', 'Haltères', 'Élastique', 'Leg curl', 'Smith machine']),
            'status' => 'available',
        ];
    }

    public function missing(): static
    {
        return $this->state(['status' => 'missing']);
    }
}
