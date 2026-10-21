<?php

namespace App\Enums;

enum DomainType: string
{
    case PRIMARY = 'primary';
    case ADDON = 'addon';
    case SUBDOMAIN = 'subdomain';

    public function label(): string
    {
        return match ($this) {
            self::PRIMARY => 'Primary Domain',
            self::ADDON => 'Addon Domain',
            self::SUBDOMAIN => 'Subdomain',
        };
    }
}
