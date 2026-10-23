<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
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
     * Determine if this enrollment token is active and valid for enrollment.
     */
    public function isValid(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
