<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property array<int, string>|null $scopes
 * @property array<int, string>|null $ip_allowlist
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property string|null $prefix
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'name',
    'token_hash',
    'prefix',
    'scopes',
    'ip_allowlist',
    'last_used_at',
    'expires_at',
    'revoked_at',
])]
class ApiToken extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a new API token returning both the model and plaintext secret.
     *
     * @param  array<int, string>  $scopes
     * @param  array<int, string>|null  $ipAllowlist
     * @return array{token: ApiToken, plainTextToken: string}
     */
    public static function createToken(
        User $user,
        string $name,
        array $scopes = ['*'],
        ?array $ipAllowlist = null,
        ?Carbon $expiresAt = null
    ): array {
        $plainTextToken = 'hp_'.Str::random(40);
        $tokenHash = hash('sha256', $plainTextToken);
        $prefix = Str::substr($plainTextToken, 0, 8);

        $token = self::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => $tokenHash,
            'prefix' => $prefix,
            'scopes' => $scopes,
            'ip_allowlist' => $ipAllowlist,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'plainTextToken' => $plainTextToken,
        ];
    }

    /**
     * Rotate this token and return the new plaintext secret.
     *
     * @return array{token: ApiToken, plainTextToken: string}
     */
    public function rotate(): array
    {
        $plainTextToken = 'hp_'.Str::random(40);
        $tokenHash = hash('sha256', $plainTextToken);
        $prefix = Str::substr($plainTextToken, 0, 8);

        $this->update([
            'token_hash' => $tokenHash,
            'prefix' => $prefix,
            'revoked_at' => null,
        ]);

        return [
            'token' => $this,
            'plainTextToken' => $plainTextToken,
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    public function can(string $scope): bool
    {
        if (empty($this->scopes) || in_array('*', $this->scopes, true)) {
            return true;
        }

        return in_array($scope, $this->scopes, true);
    }

    public function isValidForIp(?string $ip): bool
    {
        if (empty($this->ip_allowlist) || $ip === null) {
            return true;
        }

        foreach ($this->ip_allowlist as $allowed) {
            if ($allowed === $ip) {
                return true;
            }

            // Simple CIDR check
            if (str_contains($allowed, '/')) {
                [$subnet, $mask] = explode('/', $allowed);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
                    filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ipLong = ip2long($ip);
                    $subnetLong = ip2long($subnet);
                    $maskInt = (int) $mask;
                    $maskLong = ~((1 << (32 - $maskInt)) - 1);

                    if (($ipLong & $maskLong) === ($subnetLong & $maskLong)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
