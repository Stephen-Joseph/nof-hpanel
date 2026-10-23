<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Enums\AppProfile;
use App\Models\AgentJob;
use App\Models\WordPressInstall;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class UninstallWordPressJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public WordPressInstall $install,
        public bool $removeDb = true,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->install->loadMissing(['account.server', 'domain']);
        $account = $this->install->account;
        $server = $account->server;
        $domain = $this->install->domain;

        $payload = [
            'username' => $account->linux_username,
            'install_path' => $this->install->install_path,
            'remove_db' => $this->removeDb,
            'db_name' => $this->install->db_name,
            'db_user' => $this->install->db_user,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'wordpress.uninstall',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        try {
            $response = $client->execute(
                server: $server,
                action: 'wordpress.uninstall',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            if ($response['success']) {
                $domain->update(['app_profile' => AppProfile::PHP_GENERIC]);
                WriteVhostJob::dispatch($domain, $this->dryRun);

                $this->install->delete();
                $agentJob->markCompleted($response['data']);
            } else {
                $agentJob->markFailed($response['error'] ?? 'WordPress uninstall failed');
            }
        } catch (Throwable $e) {
            $agentJob->markFailed($e->getMessage());

            throw $e;
        }
    }
}
