<?php

namespace App\Enums;

enum AppProfile: string
{
    case PHP_GENERIC = 'php-generic';
    case LARAVEL = 'laravel';
    case WORDPRESS = 'wordpress';
    case STATIC_SPA = 'static-spa';

    public function label(): string
    {
        return match ($this) {
            self::PHP_GENERIC => 'PHP Generic',
            self::LARAVEL => 'Laravel Framework',
            self::WORDPRESS => 'WordPress',
            self::STATIC_SPA => 'Static HTML / Single Page App',
        };
    }
}
