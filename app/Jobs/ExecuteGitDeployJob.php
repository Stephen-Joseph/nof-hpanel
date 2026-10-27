<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\GitDeployment;
use App\Models\GitRelease;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteGitDeployJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public GitDeployment $deployment,
        public ?string $releaseTag = null,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->deployment->loadMissing(['account.server', 'account.package', 'domain']);
        $account = $this->deployment->account;
        $server = $account->server;

        $releaseTag = $this->releaseTag ?? now()->format('YmdHis');

        /** @var GitRelease $release */
        $release = $this->deployment->releases()->create([
            'release_tag' => $releaseTag,
            'status' => 'deploying',
            'log_output' => "Initiating deployment for {$this->deployment->repository_url} (branch: {$this->deployment->branch})\n",
        ]);

        $payload = [
            'username' => $account->linux_username,
            'repo_url' => $this->deployment->repository_url,
            'branch' => $this->deployment->branch,
            'deploy_path' => $this->deployment->deploy_path,
            'private_key' => $this->deployment->private_key,
            'build_steps' => $this->deployment->build_steps ?? [],
            'release_tag' => $releaseTag,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'git.deploy',
            'status' => AgentJobStatus::RUNNING,
            'payload' => array_merge($payload, ['private_key' => '***REDACTED***']),
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        try {
            $response = $client->execute(
                server: $server,
                action: 'git.deploy',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            $allLogs = implode("\n", $response['logs']);

            if ($response['success']) {
                $data = $response['data'];

                $release->update([
                    'status' => 'success',
                    'commit_hash' => $data['commit_hash'] ?? null,
                    'commit_message' => $data['commit_message'] ?? null,
                    'committer' => $data['committer'] ?? null,
                    'duration_seconds' => (float) ($data['duration_seconds'] ?? 0),
                    'log_output' => $allLogs,
                ]);

                $this->deployment->update([
                    'last_deployed_at' => now(),
                    'last_commit_hash' => $data['commit_hash'] ?? null,
                ]);

                $this->deployment->loadMissing('domain');
                if ($this->deployment->domain) {
                    \App\Jobs\SyncHtaccessRulesJob::dispatch($this->deployment->domain);
                }

                $agentJob->markCompleted($data);
            } else {
                $release->update([
                    'status' => 'failed',
                    'log_output' => $allLogs."\nError: ".($response['error'] ?? 'Deployment failed'),
                ]);

                $agentJob->markFailed($response['error'] ?? 'Deployment failed');
            }
        } catch (Throwable $e) {
            $release->update([
                'status' => 'failed',
                'log_output' => 'Exception during deployment: '.$e->getMessage(),
            ]);

            $agentJob->markFailed($e->getMessage());

            throw $e;
        }
    }
}
