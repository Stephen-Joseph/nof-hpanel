<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $database_user_id
 * @property string $token
 * @property string|null $target_db
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DatabaseUser $databaseUser
 */
class DatabaseSsoToken extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'database_user_id',
        'token',
        'target_db',
        'expires_at',
        'used_at',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * Generate a new single-use token expiring in given seconds.
     */
    public static function generate(DatabaseUser $user, ?string $targetDb = null, int $ttlSeconds = 120, ?string $ipAddress = null): self
    {
        return self::create([
            'database_user_id' => $user->id,
            'token' => Str::random(60),
            'target_db' => $targetDb,
            'expires_at' => now()->addSeconds($ttlSeconds),
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Associated database user.
     *
     * @return BelongsTo<DatabaseUser, $this>
     */
    public function databaseUser(): BelongsTo
    {
        return $this->belongsTo(DatabaseUser::class);
    }

    /**
     * Determine if token is still valid.
     */
    public function isValid(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * Mark this single-use token as consumed.
     */
    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
