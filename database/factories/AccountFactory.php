<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $usernamePrefix = fake()->regexify('[a-z]{4}');
        $usernameSuffix = fake()->unique()->numberBetween(100, 9999);

        return [
            'user_id' => User::factory(),
            'package_id' => Package::factory(),
            'server_id' => Server::factory(),
            'linux_username' => $usernamePrefix.$usernameSuffix,
            'primary_domain' => fake()->unique()->domainName(),
            'status' => AccountStatus::PENDING,
            'suspended_reason' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::PENDING,
            'suspended_reason' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::ACTIVE,
        ]);
    }

    public function suspended(?string $reason = 'Overdue invoice'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::SUSPENDED,
            'suspended_reason' => $reason,
        ]);
    }

    public function failed(?string $reason = 'Agent timeout'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::FAILED,
            'suspended_reason' => $reason,
        ]);
    }

    public function terminating(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::TERMINATING,
        ]);
    }

    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::TERMINATED,
        ]);
    }
}
