<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property int $user_id
 * @property string $db_user
 * @property string|null $target_db
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read User $user
 */
class PmaSsoSession extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'user_id',
        'db_user',
        'target_db',
        'expires_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Associated hosting account.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Authenticated user who initiated the SSO session.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if session is expired or revoked.
     */
    public function isExpiredOrRevoked(): bool
    {
        return $this->revoked_at !== null || $this->expires_at->isPast();
    }
}
