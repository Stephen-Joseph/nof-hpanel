<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically secure Base32 TOTP secret.
     */
    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        $alphabetLength = strlen(self::BASE32_ALPHABET);

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $secret;
    }

    /**
     * Calculate TOTP code for a secret at a specific timestamp.
     */
    public function getCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = floor($timestamp / 30);

        // Pack counter into 8-byte big-endian binary
        $binaryCounter = pack('N*', 0).pack('N*', $counter);
        $binaryKey = $this->base32Decode($secret);

        $hash = hash_hmac('sha1', $binaryCounter, $binaryKey, true);

        // Dynamic truncation (RFC 4226)
        $offset = ord(substr($hash, -1)) & 0x0F;
        $unpacked = unpack('N', substr($hash, $offset, 4));
        $hashPart = ($unpacked[1] ?? 0) & 0x7FFFFFFF;

        $code = (string) ($hashPart % 1000000);

        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a 6-digit TOTP code with time discrepancy window (+/- 1 step = +/- 30s).
     */
    public function verifyCode(string $secret, string $code, int $discrepancy = 1): bool
    {
        $code = trim($code);
        if (strlen($code) !== 6 || ! ctype_digit($code)) {
            return false;
        }

        $currentTime = time();

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $checkTime = $currentTime + ($i * 30);
            if (hash_equals($this->getCode($secret, $checkTime), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate an otpauth:// URI for QR code readers.
     */
    public function getOtpauthUri(string $company, string $email, string $secret): string
    {
        $label = rawurlencode($company).':'.rawurlencode($email);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            $secret,
            rawurlencode($company)
        );
    }

    /**
     * Generate standard recovery codes.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::random(10);
        }

        return $codes;
    }

    /**
     * Verify and consume a recovery code for the user.
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        /** @var list<string>|null $recoveryCodes */
        $recoveryCodes = $user->two_factor_recovery_codes;
        if (empty($recoveryCodes)) {
            return false;
        }

        $normalizedCode = trim($code);

        foreach ($recoveryCodes as $index => $storedCode) {
            if (hash_equals($storedCode, $normalizedCode)) {
                unset($recoveryCodes[$index]);
                $user->two_factor_recovery_codes = array_values($recoveryCodes);
                $user->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Base32 decode helper.
     */
    private function base32Decode(string $base32): string
    {
        $base32 = strtoupper($base32);
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0; $i < strlen($base32); $i++) {
            $val = strpos(self::BASE32_ALPHABET, $base32[$i]);
            if ($val === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
