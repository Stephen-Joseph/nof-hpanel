<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\Account;
use App\Models\AgentJob;
use App\Models\CronJob;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncCronJobsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Account $account,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->account->loadMissing(['server', 'cronJobs']);
        $server = $this->account->server;

        $jobs = $this->account->cronJobs->map(fn (CronJob $j) => [
            'id' => $j->id,
            'schedule' => $j->schedule,
            'command' => $j->command,
            'email_output' => $j->email_output ?? '',
            'is_enabled' => $j->is_enabled,
        ])->values()->all();

        $payload = [
            'username' => $this->account->linux_username,
            'jobs' => $jobs,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'cron.write',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Syncing crontab for account '%s' (%d jobs)", $this->account->linux_username, count($jobs)),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'cron.write',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            $agentJob->markCompleted($response['data']);
        } catch (Throwable $e) {
            $agentJob->recordLog('Crontab sync failed: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());
            throw $e;
        }
    }
}
