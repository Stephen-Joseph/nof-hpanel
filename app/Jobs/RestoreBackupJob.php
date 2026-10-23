<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\Account;
use App\Models\AgentJob;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RestoreBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public Account $account,
        public string $snapshotId,
        public string $restoreType,
        public ?string $targetPath = null,
        public ?string $targetDb = null,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->account->loadMissing('server');
        $server = $this->account->server;

        $payload = [
            'username' => $this->account->linux_username,
            'snapshot_id' => $this->snapshotId,
            'restore_type' => $this->restoreType,
            'target_path' => $this->targetPath,
            'target_db' => $this->targetDb,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'backup.restore',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Initiating %s restore from snapshot %s for '%s'", $this->restoreType, $this->snapshotId, $this->account->linux_username),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'backup.restore',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            $agentJob->markCompleted($response['data']);
        } catch (Throwable $e) {
            $agentJob->recordLog('Restore failed: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());
            throw $e;
        }
    }
}
