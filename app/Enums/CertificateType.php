<?php

namespace App\Enums;

enum CertificateType: string
{
    case LETS_ENCRYPT = 'lets_encrypt';
    case CUSTOM = 'custom';
    case SELF_SIGNED = 'self_signed';

    public function label(): string
    {
        return match ($this) {
            self::LETS_ENCRYPT => "Let's Encrypt (Automated)",
            self::CUSTOM => 'Custom SSL Certificate',
            self::SELF_SIGNED => 'Self-Signed',
        };
    }
}

