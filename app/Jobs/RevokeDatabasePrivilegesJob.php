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

class RevokeDatabasePrivilegesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Server $server,
        public Account $account,
        public string $dbUser,
        public string $dbName,
        public string $remoteHost = 'localhost',
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $payload = [
            'username' => $this->account->linux_username,
            'db_user' => $this->dbUser,
            'db_name' => $this->dbName,
            'remote_host' => $this->remoteHost,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $this->server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'dbuser.revoke',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Revoking privileges on '%s' from '%s'@'%s'", $this->dbName, $this->dbUser, $this->remoteHost),
            'info'
        );

        try {
            $response = $client->execute(
                server: $this->server,
                action: 'dbuser.revoke',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            $agentJob->markCompleted($response['data']);
        } catch (Throwable $e) {
            $agentJob->recordLog('Database privileges revoke failed: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());
            throw $e;
        }
    }
}
