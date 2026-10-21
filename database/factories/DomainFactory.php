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
}
