<?php

namespace App\Modules\Coach\Database\Factories;

use App\Models\User;
use App\Modules\Coach\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => 'chat',
            'title' => 'Discussion',
            'status' => 'open',
        ];
    }
}
