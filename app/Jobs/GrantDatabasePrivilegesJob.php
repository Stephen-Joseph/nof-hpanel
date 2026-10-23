<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\DatabaseUserGrant;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GrantDatabasePrivilegesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public DatabaseUserGrant $grant,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->grant->loadMissing(['database.account.server', 'databaseUser']);
        $account = $this->grant->database->account;
        $server = $account->server;

        $payload = [
            'username' => $account->linux_username,
            'db_user' => $this->grant->databaseUser->username,
            'db_name' => $this->grant->database->name,
            'privileges' => $this->grant->privileges,
            'remote_host' => $this->grant->databaseUser->remote_host,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'dbuser.grant',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf(
                "Granting privileges on '%s' to '%s'@'%s'",
                $this->grant->database->name,
                $this->grant->databaseUser->username,
                $this->grant->databaseUser->remote_host
            ),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'dbuser.grant',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            $agentJob->markCompleted($response['data']);
        } catch (Throwable $e) {
            $agentJob->recordLog('Database privileges grant failed: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());
            throw $e;
        }
    }
}
