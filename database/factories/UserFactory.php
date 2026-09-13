<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role' => UserRole::Seller,
            'parent_id' => null,
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'full_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'status' => UserStatus::Active,
            'telegram_id' => null,
            'last_login_at' => null,
            'last_login_ip' => null,
            'two_fa_secret' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
            'parent_id' => null,
        ]);
    }

    public function agent(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Agent,
        ]);
    }

    public function seller(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Seller,
        ]);
    }
}
