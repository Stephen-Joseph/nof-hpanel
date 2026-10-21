<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\Account;
use App\Models\AgentJob;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class WritePhpPoolJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<string, mixed>  $iniSettings
     */
    public function __construct(
        public Account $account,
        public string $version,
        public array $iniSettings = [],
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->account->loadMissing(['server', 'package']);
        $server = $this->account->server;
        $package = $this->account->package;

        $payload = [
            'username' => $this->account->linux_username,
            'version' => $this->version,
            'pm_mode' => 'ondemand',
            'max_children' => $package->php_workers ?? 5,
            'max_memory_mb' => $package->php_memory_mb ?? 256,
            'settings' => $this->iniSettings,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'php.pool_write',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf('Configuring PHP %s FPM pool for user %s', $this->version, $this->account->linux_username),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'php.pool_write',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            if ($response['success']) {
                $agentJob->markCompleted($response['data']);
            } else {
                $agentJob->markFailed($response['error'] ?? 'PHP pool configuration failed');
            }
        } catch (Throwable $e) {
            $agentJob->recordLog('PHP pool configuration error: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());

            throw $e;
        }
    }
}
