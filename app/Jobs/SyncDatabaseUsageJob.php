<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\Account;
use App\Models\AgentJob;
use App\Models\Database;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncDatabaseUsageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Account $account,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->account->loadMissing('server');
        $server = $this->account->server;

        $payload = [
            'username' => $this->account->linux_username,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'db.list_usage',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        try {
            $response = $client->execute(
                server: $server,
                action: 'db.list_usage',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            if (isset($response['data']['databases']) && is_array($response['data']['databases'])) {
                /** @var list<array{db_name: string, size_bytes: int, size_mb: float}> $dbList */
                $dbList = $response['data']['databases'];
                foreach ($dbList as $dbData) {
                    Database::where('account_id', $this->account->id)
                        ->where('name', $dbData['db_name'])
                        ->update(['size_bytes' => (int) $dbData['size_bytes']]);
                }
            }

            $agentJob->markCompleted($response['data']);
        } catch (Throwable $e) {
            $agentJob->markFailed($e->getMessage());
            throw $e;
        }
    }
}
