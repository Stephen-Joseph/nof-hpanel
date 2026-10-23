<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\DatabaseUser;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class UpdateDatabaseUserPasswordJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public DatabaseUser $databaseUser,
        public string $newPassword,
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
            'password' => $this->newPassword,
            'remote_host' => $this->databaseUser->remote_host,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'dbuser.set_password',
            'status' => AgentJobStatus::RUNNING,
            // Exclude plain password from logs
            'payload' => array_merge($payload, ['password' => '********']),
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Updating password for database user '%s'@'%s'", $this->databaseUser->username, $this->databaseUser->remote_host),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'dbuser.set_password',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            $agentJob->markCompleted($response['data']);
        } catch (Throwable $e) {
            $agentJob->recordLog('Database password update failed: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());
            throw $e;
        }
    }
}
