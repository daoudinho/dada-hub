<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Administrateur : accès à tous les modules et à la gestion des comptes. */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ADMIN,
        ]);
    }

    /**
     * Membre à qui ces modules sont ouverts.
     *
     * @param  string|list<string>  $slugs
     */
    public function withModules(string|array $slugs): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncModules((array) $slugs));
    }

    /**
     * Sous-modules ouverts (par exemple ['coach' => ['running']]).
     *
     * @param  array<string, list<string>>  $features
     */
    public function withFeatures(array $features): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncModuleFeatures($features));
    }
}
