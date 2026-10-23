<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\Database;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExportDatabaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Database $database,
        public ?string $outputFile = null,
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
            'output_file' => $this->outputFile,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'db.dump',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Exporting SQL dump for database '%s'", $this->database->name),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'db.dump',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            $agentJob->markCompleted($response['data']);
        } catch (Throwable $e) {
            $agentJob->recordLog('Database export failed: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());
            throw $e;
        }
    }
}
