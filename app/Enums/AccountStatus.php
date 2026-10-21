<?php

namespace App\Enums;

enum AccountStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case TERMINATING = 'terminating';
    case TERMINATED = 'terminated';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Provisioning',
            self::ACTIVE => 'Active',
            self::SUSPENDED => 'Suspended',
            self::TERMINATING => 'Terminating',
            self::TERMINATED => 'Terminated',
            self::FAILED => 'Provisioning Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'amber',
            self::ACTIVE => 'emerald',
            self::SUSPENDED => 'rose',
            self::TERMINATING => 'purple',
            self::TERMINATED => 'slate',
            self::FAILED => 'red',
        };
    }

    public function isOperational(): bool
    {
        return $this === self::ACTIVE;
    }
}
