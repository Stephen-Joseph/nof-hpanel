<?php

namespace App\Models;

use App\Enums\AgentJobStatus;
use Database\Factories\AgentJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $server_id
 * @property int|null $account_id
 * @property int|null $dispatched_by
 * @property string $action
 * @property AgentJobStatus $status
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $result
 * @property string|null $error_message
 * @property bool $dry_run
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read Account|null $account
 * @property-read User|null $dispatcher
 * @property-read Collection<int, AgentJobLog> $logs
 */
#[Fillable([
    'id',
    'server_id',
    'account_id',
    'dispatched_by',
    'action',
    'status',
    'payload',
    'result',
    'error_message',
    'dry_run',
    'attempts',
    'max_attempts',
    'started_at',
    'completed_at',
])]
class AgentJob extends Model
{
    /** @use HasFactory<AgentJobFactory> */
    use HasFactory, HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'agent_jobs';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentJobStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'dry_run' => 'boolean',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /**
     * @return HasMany<AgentJobLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(AgentJobLog::class, 'agent_job_id')->orderBy('id', 'asc');
    }

    public function recordLog(string $message, string $level = 'info'): AgentJobLog
    {
        return $this->logs()->create([
            'level' => $level,
            'message' => $message,
        ]);
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => AgentJobStatus::RUNNING,
            'started_at' => now(),
            'attempts' => $this->attempts + 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markCompleted(array $result): void
    {
        $this->update([
            'status' => AgentJobStatus::COMPLETED,
            'result' => $result,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => AgentJobStatus::FAILED,
            'error_message' => $error,
            'completed_at' => now(),
        ]);
    }
}
