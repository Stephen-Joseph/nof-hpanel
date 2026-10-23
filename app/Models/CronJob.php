<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property string $name
 * @property string $schedule
 * @property string $command
 * @property string|null $email_output
 * @property bool $is_enabled
 * @property Carbon|null $last_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
class CronJob extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'name',
        'schedule',
        'command',
        'email_output',
        'is_enabled',
        'last_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * Account owning this cron job.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Name of the per-job execution log file.
     */
    public function logFilename(): string
    {
        return "cron_{$this->id}.log";
    }

    /**
     * Human-friendly translation of common cron presets.
     */
    public function humanSchedule(): string
    {
        return match (trim($this->schedule)) {
            '* * * * *' => 'Every minute',
            '*/5 * * * *' => 'Every 5 minutes',
            '*/10 * * * *' => 'Every 10 minutes',
            '*/15 * * * *' => 'Every 15 minutes',
            '*/30 * * * *' => 'Every 30 minutes',
            '0 * * * *' => 'Hourly (at minute 0)',
            '0 0 * * *' => 'Daily at midnight (00:00)',
            '0 2 * * *' => 'Daily at 02:00 AM',
            '0 0 * * 0' => 'Weekly (Sunday at midnight)',
            '0 0 1 * *' => 'Monthly (1st of month at midnight)',
            default => $this->schedule,
        };
    }
}
