<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\Database;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CreateDatabaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Database $database,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->database->loadMissing(['account.server']);
        $account = $this->database->account;
        $server = $account->server;

        $payload = [
            'username' => $account->linux_username,
            'db_name' => $this->database->name,
            'charset' => $this->database->charset,
            'collate' => $this->database->collation,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'db.create',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Creating MariaDB database '%s' for account '%s'", $this->database->name, $account->linux_username),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'db.create',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            $agentJob->markCompleted($response['data']);
        } catch (Throwable $e) {
            $agentJob->recordLog('Database creation failed: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());
            throw $e;
        }
    }
}
