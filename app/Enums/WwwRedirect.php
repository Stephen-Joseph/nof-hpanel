<?php

namespace App\Enums;

enum WwwRedirect: string
{
    case NONE = 'none';
    case FORCE_WWW = 'force_www';
    case STRIP_WWW = 'strip_www';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'No WWW Redirection',
            self::FORCE_WWW => 'Redirect to www (example.com -> www.example.com)',
            self::STRIP_WWW => 'Redirect to non-www (www.example.com -> example.com)',
        };
    }
}
