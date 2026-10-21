<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\Account;
use App\Models\AgentJob;
use App\Models\Package;
use App\Services\AgentClient;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class UpgradeAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Account $account,
        public Package $newPackage,
        public bool $dryRun = false
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AgentClient $client, AuditService $audit): void
    {
        $this->account->loadMissing(['server', 'user', 'package']);
        $server = $this->account->server;
        $oldPackage = $this->account->package;

        $phpVersion = '8.3';
        if (! empty($this->newPackage->allowed_php_versions)) {
            $phpVersion = (string) $this->newPackage->allowed_php_versions[0];
        }

        $payload = [
            'username' => $this->account->linux_username,
            'php_version' => $phpVersion,
            'storage_mb' => $this->newPackage->storage_mb,
            'inodes' => $this->newPackage->inodes,
            'php_workers' => $this->newPackage->php_workers,
            'php_memory_mb' => $this->newPackage->php_memory_mb,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'account.upgrade',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf('Upgrading account %s to package %s', $this->account->linux_username, $this->newPackage->name),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'account.upgrade',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            if ($response['success']) {
                $agentJob->markCompleted($response['data']);
                $this->account->update(['package_id' => $this->newPackage->id]);

                $audit->log(
                    action: 'account.upgraded',
                    target: $this->account,
                    oldValues: ['package_id' => $oldPackage->id, 'package_name' => $oldPackage->name],
                    newValues: ['package_id' => $this->newPackage->id, 'package_name' => $this->newPackage->name],
                    actor: $this->account->user
                );
            } else {
                $err = $response['error'] ?? 'Agent indicated upgrade failure';
                $agentJob->markFailed($err);
            }
        } catch (Throwable $e) {
            $agentJob->recordLog('Upgrade error: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());

            throw $e;
        }
    }
}
