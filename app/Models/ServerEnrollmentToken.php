<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $token_hash
 * @property string $token
 * @property string $server_name
 * @property int|null $server_group_id
 * @property int $created_by
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $used_by_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServerGroup|null $serverGroup
 * @property-read User $creator
 */
class ServerEnrollmentToken extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'token_hash',
        'token',
        'server_name',
        'server_group_id',
        'created_by',
        'expires_at',
        'used_at',
        'used_by_ip',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<ServerGroup, $this>
     */
    public function serverGroup(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Hash raw token using SHA-256 for secure storage.
     */
    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Find a valid token by raw token value (checking hash or legacy plain token).
     */
    public static function findByRawToken(string $rawToken): ?self
    {
        $hash = self::hashToken($rawToken);

        return self::where('token_hash', $hash)
            ->orWhere('token', $rawToken)
            ->first();
    }

    /**
     * Determine if this enrollment token is active and valid for enrollment.
     */
    public function isValid(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
