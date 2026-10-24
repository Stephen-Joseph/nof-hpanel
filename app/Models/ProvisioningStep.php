<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $job_id
 * @property string $key
 * @property string $label
 * @property string $status ('pending', 'running', 'done', 'failed')
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AgentJob $job
 */
class ProvisioningStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'key',
        'label',
        'status',
        'started_at',
        'finished_at',
        'message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(AgentJob::class, 'job_id', 'id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markRunning(?string $message = null): self
    {
        $this->update([
            'status' => 'running',
            'started_at' => $this->started_at ?? now(),
            'message' => $message ?? $this->message,
        ]);

        return $this;
    }

    public function markDone(?string $message = null): self
    {
        $this->update([
            'status' => 'done',
            'finished_at' => now(),
            'message' => $message ?? $this->message,
        ]);

        return $this;
    }

    public function markFailed(string $message): self
    {
        $this->update([
            'status' => 'failed',
            'finished_at' => now(),
            'message' => $message,
        ]);

        return $this;
    }

    public function durationSeconds(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        $end = $this->finished_at ?? now();

        return (int) $this->started_at->diffInSeconds($end);
    }
}
