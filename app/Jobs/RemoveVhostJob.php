<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\Account;
use App\Models\AgentJob;
use App\Models\Server;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RemoveVhostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Server $server,
        public Account $account,
        public string $domainName,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $payload = [
            'username' => $this->account->linux_username,
            'domain' => $this->domainName,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $this->server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'vhost.remove',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf('Removing virtual host configuration for %s', $this->domainName),
            'info'
        );

        try {
            $response = $client->execute(
                server: $this->server,
                action: 'vhost.remove',
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
                $agentJob->markFailed($response['error'] ?? 'Vhost remove failed');
            }
        } catch (Throwable $e) {
            $agentJob->recordLog('Vhost remove error: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());

            throw $e;
        }
    }
}
