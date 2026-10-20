<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case RESELLER = 'reseller';
    case CUSTOMER = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrator',
            self::RESELLER => 'Reseller',
            self::CUSTOMER => 'Customer',
        };
    }
}
