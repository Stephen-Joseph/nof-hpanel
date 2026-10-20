<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
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
            'role' => UserRole::CUSTOMER,
            'status' => UserStatus::ACTIVE,
            'parent_id' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user is an administrator.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::ADMIN,
            'parent_id' => null,
        ]);
    }

    /**
     * Indicate that the user is a reseller.
     */
    public function reseller(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::RESELLER,
        ]);
    }

    /**
     * Indicate that the user is a customer owned by a reseller.
     */
    public function customer(?User $reseller = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::CUSTOMER,
            'parent_id' => $reseller?->id,
        ]);
    }

    /**
     * Indicate that the user account is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::SUSPENDED,
        ]);
    }

    /**
     * Indicate that the user has two-factor authentication enabled.
     *
     * @param  list<string>|null  $recoveryCodes
     */
    public function withTwoFactor(?string $secret = 'JBSWY3DPEHPK3PXP', ?array $recoveryCodes = null): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes ?? ['RECOVERY01', 'RECOVERY02', 'RECOVERY03'],
            'two_factor_confirmed_at' => now(),
        ]);
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
}
