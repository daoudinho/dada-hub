<?php

namespace App\Modules\Coach\Database\Factories;

use App\Models\User;
use App\Modules\Coach\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    protected $model = Goal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'sport' => 'running',
            'title' => 'Semi-marathon en 1h50',
            'event_name' => 'le semi-marathon de Paris',
            'event_date' => today()->addDays(60),
            'distance_km' => 21.1,
            'target_seconds' => 6600,
            'status' => 'active',
        ];
    }
}
