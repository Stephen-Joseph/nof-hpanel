<?php

namespace App\Console\Commands;

use App\Models\DeployRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class DeployRecordCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:record-status
        {--status= : The new status (queued, running, success, rolled_back, failed)}
        {--commit= : The commit SHA being deployed or rolled back}
        {--action= : Action type (deploy, rollback)}
        {--step= : The failed step if any}
        {--duration= : Duration in seconds}
        {--log-file= : Path to deploy log to read last 30 lines from on failure}
        {--log= : Literal log tail string}
        {--run-id= : Optional existing DeployRun ID to update}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Record deployment progress, result, and log excerpts from deployment scripts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $status = $this->option('status');
        $commit = $this->option('commit');
        $action = $this->option('action') ?? 'deploy';
        $step = $this->option('step');
        $duration = $this->option('duration');
        $logFile = $this->option('log-file');
        $log = $this->option('log');
        $runId = $this->option('run-id');

        if (! $status) {
            $this->error('The --status option is required.');
            return 1;
        }

        // Read log excerpt if requested
        $logTail = $log;
        if (! $logTail && $logFile && file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                $lastLines = array_slice($lines, -30);
                $logTail = implode("\n", $lastLines);
            }
        }

        /** @var DeployRun|null $run */
        $run = null;

        if ($runId) {
            $run = DeployRun::find($runId);
        }

        if (! $run && $commit) {
            // Find most recent queued or running run for this commit or any pending
            $run = DeployRun::where('commit_sha', $commit)
                ->whereIn('status', ['queued', 'running'])
                ->latest()
                ->first();
        }

        if (! $run) {
            // Find most recent queued run regardless of commit
            $run = DeployRun::whereIn('status', ['queued', 'running'])
                ->latest()
                ->first();
        }

        if (! $run && $commit) {
            // Create a new run record if none exists
            $run = new DeployRun();
            $run->commit_sha = $commit;
            $run->action = $action;
            $run->delivery_id = 'cli-' . bin2hex(random_bytes(4));
        }

        if (! $run) {
            $run = new DeployRun();
            $run->commit_sha = $commit ?: 'unknown';
            $run->action = $action;
            $run->delivery_id = 'cli-' . bin2hex(random_bytes(4));
        }

        $run->status = $status;
        if ($action) {
            $run->action = $action;
        }
        if ($step !== null) {
            $run->failed_step = $step;
        }
        if ($duration !== null) {
            $run->duration_seconds = (int) $duration;
        }
        if ($logTail !== null) {
            $run->log_tail = $logTail;
        }

        if ($status === 'running' && ! $run->started_at) {
            $run->started_at = Carbon::now();
        }

        if (in_array($status, ['success', 'rolled_back', 'failed'], true)) {
            $run->finished_at = Carbon::now();
            if ($run->started_at && ! $run->duration_seconds) {
                $run->duration_seconds = max(1, Carbon::now()->diffInSeconds($run->started_at));
            }
        }

        $run->save();

        $this->info("Deploy run #{$run->id} updated to status '{$status}' (commit: {$run->commit_sha}).");

        return 0;
    }
}

