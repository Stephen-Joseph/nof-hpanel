<?php

namespace App\Console\Commands;

use App\Jobs\CreateBackupJob;
use App\Models\Backup;
use App\Models\BackupSchedule;
use Illuminate\Console\Command;

class RunScheduledBackupsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backups:run-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch automated restic backup jobs for accounts whose schedules are due';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dueSchedules = BackupSchedule::where('is_enabled', true)
            ->where(function ($query) {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            })
            ->with(['account.package', 'account.server'])
            ->get();

        $this->info(sprintf('Found %d scheduled backup(s) due for execution.', $dueSchedules->count()));

        foreach ($dueSchedules as $schedule) {
            $account = $schedule->account;

            if ($account->hasRunningBackup()) {
                $this->warn(sprintf("Account '%s' already has a backup in progress. Skipping.", $account->linux_username));

                continue;
            }

            $backup = Backup::create([
                'account_id' => $account->id,
                'backup_type' => 'scheduled',
                'status' => 'pending',
            ]);

            CreateBackupJob::dispatch($backup);

            $schedule->update([
                'last_run_at' => now(),
                'next_run_at' => $schedule->calculateNextRun(now()),
            ]);

            $this->info(sprintf("Dispatched scheduled backup for '%s' (Next run: %s)", $account->linux_username, $schedule->next_run_at?->toDateTimeString()));
        }

        return self::SUCCESS;
    }
}
