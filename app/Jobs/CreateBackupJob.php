<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\Backup;
use App\Services\AgentClient;
use App\Services\WebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CreateBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600; // 10 minutes max for large restic snapshots

    public function __construct(
        public Backup $backup,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client, WebhookService $webhooks): void
    {
        $this->backup->loadMissing(['account.server', 'account.package', 'account.databases']);
        $account = $this->backup->account;
        $server = $account->server;

        $this->backup->update(['status' => 'running']);

        $databases = $account->databases->pluck('name')->all();

        $payload = [
            'username' => $account->linux_username,
            'backup_type' => $this->backup->backup_type,
            'databases' => $databases,
            'retention_count' => $account->package->backups_retained,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'backup.create',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Starting %s backup for account '%s'", $this->backup->backup_type, $account->linux_username),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'backup.create',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            $agentJob->markCompleted($response['data']);

            $this->backup->update([
                'status' => 'completed',
                'snapshot_id' => $response['data']['snapshot_id'] ?? null,
                'size_bytes' => (int) ($response['data']['bytes_added'] ?? 0),
                'file_count' => (int) ($response['data']['file_count'] ?? 0),
                'duration_seconds' => (float) ($response['data']['duration_seconds'] ?? 0),
                'databases_included' => $databases,
                'completed_at' => now(),
            ]);

            $webhooks->dispatch('backup.completed', [
                'account_id' => $account->id,
                'username' => $account->linux_username,
                'snapshot_id' => $this->backup->snapshot_id,
                'backup_type' => $this->backup->backup_type,
                'size_bytes' => $this->backup->size_bytes,
                'duration_seconds' => $this->backup->duration_seconds,
            ], $account->user);
        } catch (Throwable $e) {
            $agentJob->recordLog('Backup creation failed: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());

            $this->backup->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $webhooks->dispatch('backup.failed', [
                'account_id' => $account->id,
                'username' => $account->linux_username,
                'backup_type' => $this->backup->backup_type,
                'error' => $e->getMessage(),
            ], $account->user);

            throw $e;
        }
    }
}
