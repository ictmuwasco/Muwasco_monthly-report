<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

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
     * Define the model's default state (matches the live `users` schema).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username'   => fake()->unique()->userName(),
            'password'   => static::$password ??= Hash::make('password'),
            'full_name'  => fake()->name(),
            'email'      => fake()->unique()->safeEmail(),
            'role'       => 'user',
            'is_active'  => true,
        ];
    }

    /**
     * Make the user a system administrator.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    /**
     * Make the user inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
