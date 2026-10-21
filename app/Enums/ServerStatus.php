<?php

namespace App\Enums;

enum ServerStatus: string
{
    case ACTIVE = 'active';
    case MAINTENANCE = 'maintenance';
    case DRAINING = 'draining';
    case OFFLINE = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::MAINTENANCE => 'Maintenance',
            self::DRAINING => 'Draining (No New Accounts)',
            self::OFFLINE => 'Offline',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE => 'emerald',
            self::MAINTENANCE => 'amber',
            self::DRAINING => 'indigo',
            self::OFFLINE => 'rose',
        };
    }

    public function canAcceptAccounts(): bool
    {
        return $this === self::ACTIVE;
    }
}
