<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property float $storage_mb
 * @property int $inodes
 * @property int $bandwidth_bytes
 * @property int $database_bytes
 * @property int $fpm_active_workers
 * @property int $fpm_idle_workers
 * @property int $fpm_total_workers
 * @property array<string, mixed>|null $raw_metrics
 * @property Carbon $recorded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
class UsageSnapshot extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'storage_mb',
        'inodes',
        'bandwidth_bytes',
        'database_bytes',
        'fpm_active_workers',
        'fpm_idle_workers',
        'fpm_total_workers',
        'raw_metrics',
        'recorded_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'storage_mb' => 'float',
        'inodes' => 'integer',
        'bandwidth_bytes' => 'integer',
        'database_bytes' => 'integer',
        'fpm_active_workers' => 'integer',
        'fpm_idle_workers' => 'integer',
        'fpm_total_workers' => 'integer',
        'raw_metrics' => 'array',
        'recorded_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
