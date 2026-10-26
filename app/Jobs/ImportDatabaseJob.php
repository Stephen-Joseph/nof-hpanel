<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\Database;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportDatabaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900; // 15 minutes timeout for large dumps

    public function __construct(
        public Database $database,
        public string $inputFile,
        public bool $dryRun = false,
        public ?int $userId = null
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->database->loadMissing(['account.server']);
        $account = $this->database->account;
        $server = $account->server;

        $payload = [
            'username' => $account->linux_username,
            'db_name' => $this->database->name,
            'input_file' => $this->inputFile,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $this->userId ?? $account->user_id,
            'action' => 'db.import',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Starting SQL import of '%s' into database '%s'", $this->inputFile, $this->database->name),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'db.import',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            if (isset($response['logs']) && is_array($response['logs'])) {
                foreach ($response['logs'] as $logLine) {
                    $agentJob->recordLog((string) $logLine, 'info');
                }
            }

            $agentJob->markCompleted($response['data'] ?? []);
        } catch (Throwable $e) {
            $errorMessage = mb_substr($e->getMessage(), 0, 500);
            $agentJob->recordLog('Database import failed: '.$errorMessage, 'error');
            $agentJob->markFailed($errorMessage);
            throw $e;
        }
    }
}

