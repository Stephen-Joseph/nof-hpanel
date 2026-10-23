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

class DropDatabaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Server $server,
        public Account $account,
        public string $databaseName,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $payload = [
            'username' => $this->account->linux_username,
            'db_name' => $this->databaseName,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $this->server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'db.drop',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Dropping MariaDB database '%s' for account '%s'", $this->databaseName, $this->account->linux_username),
            'info'
        );

        try {
            $response = $client->execute(
                server: $this->server,
                action: 'db.drop',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            $agentJob->markCompleted($response['data']);
        } catch (Throwable $e) {
            $agentJob->recordLog('Database drop failed: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());
            throw $e;
        }
    }
}
