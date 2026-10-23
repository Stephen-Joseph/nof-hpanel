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

class DropDatabaseUserJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Server $server,
        public Account $account,
        public string $dbUser,
        public string $remoteHost = 'localhost',
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $payload = [
            'username' => $this->account->linux_username,
            'db_user' => $this->dbUser,
            'remote_host' => $this->remoteHost,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $this->server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'dbuser.drop',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Dropping database user '%s'@'%s'", $this->dbUser, $this->remoteHost),
            'info'
        );

        try {
            $response = $client->execute(
                server: $this->server,
                action: 'dbuser.drop',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            $agentJob->markCompleted($response['data']);
        } catch (Throwable $e) {
            $agentJob->recordLog('Database user drop failed: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());
            throw $e;
        }
    }
}
