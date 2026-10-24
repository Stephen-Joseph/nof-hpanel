<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $api_token_id
 * @property string $method
 * @property string $path
 * @property int $status_code
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property int|null $duration_ms
 * @property Carbon $created_at
 * @property-read User|null $user
 * @property-read ApiToken|null $token
 */
#[Fillable([
    'user_id',
    'api_token_id',
    'method',
    'path',
    'status_code',
    'ip_address',
    'user_agent',
    'duration_ms',
    'created_at',
])]
class ApiRequestLog extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
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
     * @return BelongsTo<ApiToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'api_token_id');
    }
}

