<?php

namespace Database\Factories;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Models\Certificate;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'type' => CertificateType::LETS_ENCRYPT,
            'status' => CertificateStatus::ACTIVE,
            'cert_path' => '/etc/letsencrypt/live/example.com/fullchain.pem',
            'key_path' => '/etc/letsencrypt/live/example.com/privkey.pem',
            'issuer' => "Let's Encrypt Authority X3",
            'valid_from' => now()->subDays(10),
            'valid_to' => now()->addDays(80),
            'auto_renew' => true,
            'hsts_enabled' => false,
            'last_error' => null,
        ];
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_to' => now()->addDays(15),
        ]);
    }

    public function custom(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CertificateType::CUSTOM,
            'issuer' => 'DigiCert Global Root CA',
            'cert_path' => '/etc/ssl/panel/custom.com/certificate.crt',
            'key_path' => '/etc/ssl/panel/custom.com/private.key',
        ]);
    }
}

