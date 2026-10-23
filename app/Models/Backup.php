<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property string|null $snapshot_id
 * @property string $backup_type
 * @property string $status
 * @property int $size_bytes
 * @property int $file_count
 * @property float $duration_seconds
 * @property array<int, string>|null $databases_included
 * @property string|null $error_message
 * @property string|null $download_path
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
class Backup extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'snapshot_id',
        'backup_type',
        'status',
        'size_bytes',
        'file_count',
        'duration_seconds',
        'databases_included',
        'error_message',
        'download_path',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'file_count' => 'integer',
            'duration_seconds' => 'float',
            'databases_included' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Account owning this backup.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function shortSnapshotId(): string
    {
        if (! $this->snapshot_id) {
            return 'Pending';
        }

        return substr($this->snapshot_id, 0, 8);
    }

    public function formattedSize(): string
    {
        if ($this->size_bytes < 1024) {
            return $this->size_bytes.' B';
        }
        if ($this->size_bytes < 1024 * 1024) {
            return round($this->size_bytes / 1024, 2).' KB';
        }
        if ($this->size_bytes < 1024 * 1024 * 1024) {
            return round($this->size_bytes / (1024 * 1024), 2).' MB';
        }

        return round($this->size_bytes / (1024 * 1024 * 1024), 2).' GB';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['pending', 'running'], true);
    }
}
