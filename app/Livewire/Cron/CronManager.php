<?php

namespace App\Livewire\Cron;

use App\Jobs\SyncCronJobsJob;
use App\Models\Account;
use App\Models\CronJob;
use App\Services\AgentClient;
use Illuminate\View\View;
use Livewire\Component;

class CronManager extends Component
{
    public Account $account;

    // Form fields
    public string $name = '';

    public string $preset = 'every_15_minutes';

    public string $schedule = '*/15 * * * *';

    public string $minute = '*/15';

    public string $hour = '*';

    public string $dayOfMonth = '*';

    public string $month = '*';

    public string $dayOfWeek = '*';

    public string $command = '';

    public string $emailOutput = '';

    // State & Modals
    public bool $isEditing = false;

    public ?int $editingJobId = null;

    public ?int $jobToDeleteId = null;

    public bool $showingLogModal = false;

    public ?int $viewingLogJobId = null;

    /** @var list<string> */
    public array $viewingLogLines = [];

    public string $viewingLogName = '';

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['package', 'server', 'cronJobs']);
        $this->command = "/usr/bin/php /home/{$account->linux_username}/public_html/artisan schedule:run";
    }

    public function updatedPreset(string $value): void
    {
        match ($value) {
            'every_minute' => $this->setScheduleParts('*', '*', '*', '*', '*'),
            'every_5_minutes' => $this->setScheduleParts('*/5', '*', '*', '*', '*'),
            'every_15_minutes' => $this->setScheduleParts('*/15', '*', '*', '*', '*'),
            'every_30_minutes' => $this->setScheduleParts('*/30', '*', '*', '*', '*'),
            'hourly' => $this->setScheduleParts('0', '*', '*', '*', '*'),
            'daily_midnight' => $this->setScheduleParts('0', '0', '*', '*', '*'),
            'daily_2am' => $this->setScheduleParts('0', '2', '*', '*', '*'),
            'weekly_sunday' => $this->setScheduleParts('0', '0', '*', '*', '0'),
            'monthly_first' => $this->setScheduleParts('0', '0', '1', '*', '*'),
            default => null,
        };
    }

    protected function setScheduleParts(string $min, string $hour, string $dom, string $mon, string $dow): void
    {
        $this->minute = $min;
        $this->hour = $hour;
        $this->dayOfMonth = $dom;
        $this->month = $mon;
        $this->dayOfWeek = $dow;
        $this->schedule = "{$min} {$hour} {$dom} {$mon} {$dow}";
    }

    public function updatedMinute(): void
    {
        $this->preset = 'custom';
        $this->updateScheduleString();
    }

    public function updatedHour(): void
    {
        $this->preset = 'custom';
        $this->updateScheduleString();
    }

    public function updatedDayOfMonth(): void
    {
        $this->preset = 'custom';
        $this->updateScheduleString();
    }

    public function updatedMonth(): void
    {
        $this->preset = 'custom';
        $this->updateScheduleString();
    }

    public function updatedDayOfWeek(): void
    {
        $this->preset = 'custom';
        $this->updateScheduleString();
    }

    protected function updateScheduleString(): void
    {
        $this->schedule = trim("{$this->minute} {$this->hour} {$this->dayOfMonth} {$this->month} {$this->dayOfWeek}");
    }

    public function saveJob(): void
    {
        $this->authorize('view', $this->account);

        $this->updateScheduleString();

        $maxAllowed = $this->account->package->max_cron_jobs;
        if (! $this->isEditing && $this->account->cronJobs()->count() >= $maxAllowed) {
            $this->addError('name', "Package limit reached (Max: {$maxAllowed} cron jobs).");

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'schedule' => [
                'required',
                'string',
                'max:100',
                'regex:/^([0-9*,\/\-]+)\s+([0-9*,\/\-]+)\s+([0-9*,\/\-]+)\s+([0-9*,\/\-]+)\s+([0-9*,\/\-]+)$/',
            ],
            'command' => ['required', 'string', 'max:1000', 'not_regex:/[\r\n\x00]/', 'not_regex:/\b(sudo|su|doas)\b/i'],
            'emailOutput' => ['nullable', 'email', 'max:255'],
        ]);

        if ($this->isEditing && $this->editingJobId) {
            /** @var CronJob $job */
            $job = $this->account->cronJobs()->findOrFail($this->editingJobId);
            $job->update([
                'name' => $this->name,
                'schedule' => $this->schedule,
                'command' => $this->command,
                'email_output' => $this->emailOutput ?: null,
            ]);

            session()->flash('status', "Cron job '{$job->name}' updated.");
        } else {
            CronJob::create([
                'account_id' => $this->account->id,
                'name' => $this->name,
                'schedule' => $this->schedule,
                'command' => $this->command,
                'email_output' => $this->emailOutput ?: null,
                'is_enabled' => true,
            ]);

            session()->flash('status', "Cron job '{$this->name}' created.");
        }

        SyncCronJobsJob::dispatch($this->account);

        $this->resetForm();
    }

    public function editJob(int $id): void
    {
        $this->authorize('view', $this->account);

        /** @var CronJob $job */
        $job = $this->account->cronJobs()->findOrFail($id);

        $this->isEditing = true;
        $this->editingJobId = $job->id;
        $this->name = $job->name;
        $this->schedule = $job->schedule;
        $this->command = $job->command;
        $this->emailOutput = $job->email_output ?? '';

        $parts = explode(' ', trim($job->schedule));
        if (count($parts) === 5) {
            $this->minute = $parts[0];
            $this->hour = $parts[1];
            $this->dayOfMonth = $parts[2];
            $this->month = $parts[3];
            $this->dayOfWeek = $parts[4];
        }
        $this->preset = 'custom';
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function toggleJob(int $id): void
    {
        $this->authorize('view', $this->account);

        /** @var CronJob $job */
        $job = $this->account->cronJobs()->findOrFail($id);
        $job->update(['is_enabled' => ! $job->is_enabled]);

        SyncCronJobsJob::dispatch($this->account);

        session()->flash('status', sprintf("Cron job '%s' %s.", $job->name, $job->is_enabled ? 'enabled' : 'disabled'));
    }

    public function confirmDelete(int $id): void
    {
        $this->jobToDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->jobToDeleteId = null;
    }

    public function deleteJob(): void
    {
        $this->authorize('view', $this->account);

        if (! $this->jobToDeleteId) {
            return;
        }

        /** @var CronJob $job */
        $job = $this->account->cronJobs()->findOrFail($this->jobToDeleteId);
        $name = $job->name;
        $job->delete();

        SyncCronJobsJob::dispatch($this->account);

        $this->jobToDeleteId = null;
        session()->flash('status', "Cron job '{$name}' deleted.");
    }

    public function viewOutputLog(int $id, AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        /** @var CronJob $job */
        $job = $this->account->cronJobs()->findOrFail($id);
        $this->viewingLogJobId = $job->id;
        $this->viewingLogName = $job->name;

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'log.tail',
                payload: [
                    'username' => $this->account->linux_username,
                    'log_file' => $job->logFilename(),
                    'lines' => 100,
                ],
                dryRun: false
            );

            /** @var list<string> $lines */
            $lines = $response['data']['lines'] ?? [];
            $this->viewingLogLines = $lines;
        } catch (\Throwable $e) {
            $this->viewingLogLines = ['Could not retrieve output log: '.$e->getMessage()];
        }

        $this->showingLogModal = true;
    }

    public function closeLogModal(): void
    {
        $this->showingLogModal = false;
        $this->viewingLogJobId = null;
        $this->viewingLogLines = [];
        $this->viewingLogName = '';
    }

    protected function resetForm(): void
    {
        $this->isEditing = false;
        $this->editingJobId = null;
        $this->name = '';
        $this->preset = 'every_15_minutes';
        $this->setScheduleParts('*/15', '*', '*', '*', '*');
        $this->command = "/usr/bin/php /home/{$this->account->linux_username}/public_html/artisan schedule:run";
        $this->emailOutput = '';
    }

    public function render(): View
    {
        $jobs = $this->account->cronJobs()->latest()->get();

        return view('livewire.cron.cron-manager', [
            'jobs' => $jobs,
        ]);
    }
}
