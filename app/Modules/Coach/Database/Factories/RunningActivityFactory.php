<?php

namespace App\Modules\Coach\Database\Factories;

use App\Models\User;
use App\Modules\Coach\Models\RunningActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunningActivity>
 */
class RunningActivityFactory extends Factory
{
    protected $model = RunningActivity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => 'manual',
            'started_at' => now()->subHours(2),
            'distance_m' => 8000,
            'moving_seconds' => 2700,
            'avg_hr' => 148,
        ];
    }
}
