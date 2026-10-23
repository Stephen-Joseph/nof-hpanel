<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property string $frequency
 * @property string $time_of_day
 * @property int $day_of_week
 * @property int $day_of_month
 * @property bool $is_enabled
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
class BackupSchedule extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'frequency',
        'time_of_day',
        'day_of_week',
        'day_of_month',
        'is_enabled',
        'last_run_at',
        'next_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'is_enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /**
     * Account owning this schedule.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Compute next scheduled execution timestamp.
     */
    public function calculateNextRun(?Carbon $from = null): Carbon
    {
        $base = $from ? $from->copy() : now();
        [$hour, $minute] = explode(':', $this->time_of_day ?: '02:00');

        $candidate = $base->copy()->setTime((int) $hour, (int) $minute, 0);

        if ($this->frequency === 'daily') {
            if ($candidate->isPast()) {
                $candidate->addDay();
            }

            return $candidate;
        }

        if ($this->frequency === 'weekly') {
            $candidate->next($this->day_of_week);
            if ($candidate->isPast()) {
                $candidate->addWeek();
            }

            return $candidate;
        }

        if ($this->frequency === 'monthly') {
            $candidate->day((int) min($this->day_of_month, $candidate->daysInMonth));
            if ($candidate->isPast()) {
                $candidate->addMonth();
            }

            return $candidate;
        }

        return $candidate->addDay();
    }
}
