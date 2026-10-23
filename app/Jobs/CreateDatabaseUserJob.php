<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\DatabaseUser;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CreateDatabaseUserJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public DatabaseUser $databaseUser,
        public string $password,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->databaseUser->loadMissing(['account.server']);
        $account = $this->databaseUser->account;
        $server = $account->server;

        $payload = [
            'username' => $account->linux_username,
            'db_user' => $this->databaseUser->username,
            'password' => $this->password,
            'remote_host' => $this->databaseUser->remote_host,
            'max_user_connections' => $this->databaseUser->max_connections,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'dbuser.create',
            'status' => AgentJobStatus::RUNNING,
            // Exclude plain password from persisted job payload logs for security (Rule 9)
            'payload' => array_merge($payload, ['password' => '********']),
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Creating database user '%s'@'%s'", $this->databaseUser->username, $this->databaseUser->remote_host),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'dbuser.create',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            $agentJob->markCompleted($response['data']);
        } catch (Throwable $e) {
            $agentJob->recordLog('Database user creation failed: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());
            throw $e;
        }
    }
}
