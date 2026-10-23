<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $domain_id
 * @property string $path
 * @property string $realm
 * @property string $htpasswd_filename
 * @property array<int, array{username: string, hash: string}> $users
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Domain $domain
 */
class ProtectedDirectory extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'domain_id',
        'path',
        'realm',
        'htpasswd_filename',
        'users',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'users' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
