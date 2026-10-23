<?php

namespace App\Services;

class HtpasswdHelper
{
    /**
     * Hash a raw password for an .htpasswd file.
     * Uses PHP's standard bcrypt implementation (supported by modern Nginx).
     */
    public static function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_BCRYPT);
    }
}
