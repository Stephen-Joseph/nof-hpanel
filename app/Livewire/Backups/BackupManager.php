<?php

namespace App\Livewire\Backups;

use App\Jobs\CreateBackupJob;
use App\Jobs\DeleteBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Models\Account;
use App\Models\Backup;
use App\Models\BackupSchedule;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class BackupManager extends Component
{
    public Account $account;

    // Automated schedule configuration
    public string $scheduleFrequency = 'daily';

    public string $scheduleTime = '02:00';

    public int $scheduleDayOfWeek = 0;

    public int $scheduleDayOfMonth = 1;

    public bool $scheduleEnabled = true;

    // Restore wizard state
    public bool $showingRestoreModal = false;

    public ?int $restoreBackupId = null;

    public string $restoreSnapshotId = '';

    public string $restoreType = 'full';

    public string $restoreTargetPath = '';

    public string $restoreTargetDb = '';

    // Delete confirmation
    public ?int $backupToDeleteId = null;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['package', 'server', 'backupSchedule', 'databases']);

        $schedule = $this->account->backupSchedule;
        if ($schedule) {
            $this->scheduleFrequency = $schedule->frequency;
            $this->scheduleTime = $schedule->time_of_day;
            $this->scheduleDayOfWeek = $schedule->day_of_week;
            $this->scheduleDayOfMonth = $schedule->day_of_month;
            $this->scheduleEnabled = $schedule->is_enabled;
        }
    }

    public function createManualBackup(): void
    {
        $this->authorize('view', $this->account);

        if ($this->account->hasRunningBackup()) {
            $this->addError('manual', 'A backup snapshot is already in progress for this account.');

            return;
        }

        $backup = Backup::create([
            'account_id' => $this->account->id,
            'backup_type' => 'manual',
            'status' => 'pending',
        ]);

        CreateBackupJob::dispatch($backup);

        session()->flash('status', 'Manual backup snapshot creation initiated.');
    }

    public function saveSchedule(): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'scheduleFrequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'scheduleTime' => ['required', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'scheduleDayOfWeek' => ['required', 'integer', 'min:0', 'max:6'],
            'scheduleDayOfMonth' => ['required', 'integer', 'min:1', 'max:31'],
            'scheduleEnabled' => ['boolean'],
        ]);

        /** @var BackupSchedule $schedule */
        $schedule = BackupSchedule::updateOrCreate(
            ['account_id' => $this->account->id],
            [
                'frequency' => $this->scheduleFrequency,
                'time_of_day' => $this->scheduleTime,
                'day_of_week' => $this->scheduleDayOfWeek,
                'day_of_month' => $this->scheduleDayOfMonth,
                'is_enabled' => $this->scheduleEnabled,
            ]
        );

        $schedule->update(['next_run_at' => $schedule->calculateNextRun(now())]);

        session()->flash('status', 'Automated backup schedule policy saved.');
    }

    public function openRestoreWizard(int $backupId): void
    {
        $this->authorize('view', $this->account);

        /** @var Backup $backup */
        $backup = $this->account->backups()->findOrFail($backupId);

        if (! $backup->snapshot_id) {
            session()->flash('error', 'Cannot restore a backup that does not have a valid snapshot ID.');

            return;
        }

        $this->restoreBackupId = $backup->id;
        $this->restoreSnapshotId = $backup->snapshot_id;
        $this->restoreType = 'full';
        $this->restoreTargetPath = "/home/{$this->account->linux_username}/public_html";
        $firstDb = $this->account->databases->first();
        $this->restoreTargetDb = $firstDb ? $firstDb->name : '';
        $this->showingRestoreModal = true;
    }

    public function closeRestoreWizard(): void
    {
        $this->showingRestoreModal = false;
        $this->restoreBackupId = null;
        $this->restoreSnapshotId = '';
        $this->restoreType = 'full';
        $this->restoreTargetPath = '';
        $this->restoreTargetDb = '';
    }

    public function executeRestore(): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'restoreType' => ['required', Rule::in(['full', 'files_only', 'databases_only', 'single_db', 'single_path'])],
            'restoreSnapshotId' => ['required', 'regex:/^[a-f0-9]{8,64}$/'],
            'restoreTargetDb' => [
                Rule::requiredIf(fn () => $this->restoreType === 'single_db'),
                'nullable',
                'string',
            ],
            'restoreTargetPath' => [
                Rule::requiredIf(fn () => $this->restoreType === 'single_path'),
                'nullable',
                'string',
            ],
        ]);

        RestoreBackupJob::dispatch(
            account: $this->account,
            snapshotId: $this->restoreSnapshotId,
            restoreType: $this->restoreType,
            targetPath: $this->restoreType === 'single_path' ? $this->restoreTargetPath : null,
            targetDb: $this->restoreType === 'single_db' ? $this->restoreTargetDb : null,
        );

        $this->closeRestoreWizard();
        session()->flash('status', 'Restore job queued. Existing files or databases in the target scope will be safely reverted.');
    }

    public function confirmDelete(int $id): void
    {
        $this->backupToDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->backupToDeleteId = null;
    }

    public function deleteBackup(): void
    {
        $this->authorize('view', $this->account);

        if (! $this->backupToDeleteId) {
            return;
        }

        /** @var Backup $backup */
        $backup = $this->account->backups()->findOrFail($this->backupToDeleteId);

        if ($backup->snapshot_id) {
            DeleteBackupJob::dispatch($this->account->server, $this->account, $backup->snapshot_id);
        }

        $backup->delete();
        $this->backupToDeleteId = null;

        session()->flash('status', 'Backup snapshot removed.');
    }

    public function render(): View
    {
        $backups = $this->account->backups()->get();

        return view('livewire.backups.backup-manager', [
            'backups' => $backups,
            'databases' => $this->account->databases,
        ]);
    }
}
