<?php

namespace App\Modules\Coach\Database\Factories;

use App\Models\User;
use App\Modules\Coach\Models\RunningSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunningSession>
 */
class RunningSessionFactory extends Factory
{
    protected $model = RunningSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'scheduled_on' => today(),
            'type' => 'easy',
            'title' => 'Footing facile',
            'duration_minutes' => 45,
            'target_pace' => '6:00 /km',
            'target_hr' => 'Z2',
            'status' => 'planned',
        ];
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'done']);
    }
}
