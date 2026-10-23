<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\ProtectedDirectory;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncProtectedDirectoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public ProtectedDirectory $directory,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->directory->loadMissing(['domain.account.server']);
        $domain = $this->directory->domain;
        $account = $domain->account;
        $server = $account->server;

        // If inactive or marked for deletion, delete the htpasswd file
        if (! $this->directory->is_active || empty($this->directory->users)) {
            $payload = [
                'username' => $account->linux_username,
                'filename' => $this->directory->htpasswd_filename,
            ];

            $agentJob = AgentJob::create([
                'server_id' => $server->id,
                'account_id' => $account->id,
                'dispatched_by' => $account->user_id,
                'action' => 'htpasswd.delete',
                'status' => AgentJobStatus::RUNNING,
                'payload' => $payload,
                'dry_run' => $this->dryRun,
                'attempts' => 1,
                'started_at' => now(),
            ]);

            try {
                $response = $client->execute(
                    server: $server,
                    action: 'htpasswd.delete',
                    payload: $payload,
                    dryRun: $this->dryRun,
                    requestId: $agentJob->id
                );

                if ($response['success']) {
                    $agentJob->markCompleted($response['data']);
                } else {
                    $agentJob->markFailed($response['error'] ?? 'htpasswd deletion failed');
                }
            } catch (Throwable $e) {
                $agentJob->markFailed($e->getMessage());
            }

            WriteVhostJob::dispatch($domain, $this->dryRun);

            return;
        }

        // Format entries for htpasswd.write
        $entries = collect($this->directory->users)->map(fn ($u) => [
            'user' => $u['username'],
            'hash' => $u['hash'],
        ])->values()->all();

        $payload = [
            'username' => $account->linux_username,
            'filename' => $this->directory->htpasswd_filename,
            'entries' => $entries,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'htpasswd.write',
            'status' => AgentJobStatus::RUNNING,
            'payload' => array_merge($payload, ['entries' => '***REDACTED***']),
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        try {
            $response = $client->execute(
                server: $server,
                action: 'htpasswd.write',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            if ($response['success']) {
                $agentJob->markCompleted($response['data']);
                // Reload virtual host atomically with the new protected directory block
                WriteVhostJob::dispatch($domain, $this->dryRun);
            } else {
                $agentJob->markFailed($response['error'] ?? 'htpasswd write failed');
            }
        } catch (Throwable $e) {
            $agentJob->markFailed($e->getMessage());

            throw $e;
        }
    }
}
