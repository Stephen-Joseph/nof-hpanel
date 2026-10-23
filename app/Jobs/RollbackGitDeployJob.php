<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\GitDeployment;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RollbackGitDeployJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public GitDeployment $deployment,
        public string $targetReleaseTag,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->deployment->loadMissing(['account.server']);
        $account = $this->deployment->account;
        $server = $account->server;

        $payload = [
            'username' => $account->linux_username,
            'deploy_path' => $this->deployment->deploy_path,
            'target_release_tag' => $this->targetReleaseTag,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'git.rollback',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        try {
            $response = $client->execute(
                server: $server,
                action: 'git.rollback',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            if ($response['success']) {
                $this->deployment->releases()
                    ->where('release_tag', $this->targetReleaseTag)
                    ->update(['status' => 'success']);

                $this->deployment->update([
                    'last_deployed_at' => now(),
                ]);

                $agentJob->markCompleted($response['data']);
            } else {
                $agentJob->markFailed($response['error'] ?? 'Rollback failed');
            }
        } catch (Throwable $e) {
            $agentJob->markFailed($e->getMessage());

            throw $e;
        }
    }
}
