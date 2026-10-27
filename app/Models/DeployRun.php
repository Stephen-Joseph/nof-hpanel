<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $commit_sha
 * @property string|null $commit_message
 * @property string|null $author
 * @property string|null $delivery_id
 * @property string $status
 * @property string $action
 * @property string|null $failed_step
 * @property int|null $duration_seconds
 * @property string|null $log_tail
 * @property bool $has_migrations
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'commit_sha',
    'commit_message',
    'author',
    'delivery_id',
    'status',
    'action',
    'failed_step',
    'duration_seconds',
    'log_tail',
    'has_migrations',
    'started_at',
    'finished_at',
])]
class DeployRun extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'has_migrations' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'rolled_back'], true);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}

