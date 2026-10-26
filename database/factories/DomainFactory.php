<?php

namespace Database\Factories;

use App\Enums\AppProfile;
use App\Enums\DomainType;
use App\Enums\WwwRedirect;
use App\Models\Account;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domainName = fake()->unique()->domainName();

        return [
            'account_id' => Account::factory(),
            'domain' => $domainName,
            'type' => DomainType::PRIMARY,
            'document_root' => '/home/user/public_html',
            'app_profile' => AppProfile::PHP_GENERIC,
            'php_version' => '8.3',
            'force_https' => false,
            'www_redirect' => WwwRedirect::NONE,
            'is_active' => true,
        ];
    }

    public function addon(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DomainType::ADDON,
        ]);
    }

    public function subdomain(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DomainType::SUBDOMAIN,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => 'verified',
            'verified_at' => now(),
            'dns_consecutive_failures' => 0,
            'dns_last_error' => null,
        ]);
    }

    public function sslActive(): static
    {
        return $this->state(fn (array $attributes) => [
            'ssl_status' => 'active',
            'ssl_expires_at' => now()->addDays(90),
            'ssl_last_error' => null,
        ]);
    }
}
