<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Plan',
            'storage_mb' => 10240,
            'inodes' => 100000,
            'bandwidth_gb' => 100,
            'max_websites' => 1,
            'max_subdomains' => 5,
            'max_databases' => 5,
            'max_db_users' => 5,
            'max_ftp_accounts' => 5,
            'max_cron_jobs' => 5,
            'php_workers' => 5,
            'php_memory_mb' => 256,
            'allowed_php_versions' => ['8.2', '8.3', '8.4'],
            'backups_retained' => 3,
            'ssh_enabled' => false,
            'git_enabled' => false,
            'reseller_owner_id' => null,
        ];
    }

    public function forReseller(User $reseller): static
    {
        return $this->state(fn (array $attributes) => [
            'reseller_owner_id' => $reseller->id,
        ]);
    }

    public function withSsh(): static
    {
        return $this->state(fn (array $attributes) => [
            'ssh_enabled' => true,
        ]);
    }

    public function withGit(): static
    {
        return $this->state(fn (array $attributes) => [
            'git_enabled' => true,
        ]);
    }
}
