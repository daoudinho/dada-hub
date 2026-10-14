<?php

namespace App\Modules\Agenda\Database\Factories;

use App\Modules\Agenda\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'starts_on' => now('Europe/Madrid')->addDays(fake()->numberBetween(0, 30))->toDateString(),
            'ends_on' => null,
            'time' => fake()->optional()->time('H:i'),
            'place' => fake()->optional()->city(),
            'url' => fake()->optional()->url(),
            'category' => fake()->randomElement(['fete', 'cine', 'musique', 'scene', 'expo', 'photo', 'manga', 'sport', 'barca']),
            'source' => 'manual',
        ];
    }
}
