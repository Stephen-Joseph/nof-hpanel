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

class InstallWordPressJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public WordPressInstall $install,
        public string $adminPassword,
        public string $dbPassword,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->install->loadMissing(['account.server', 'domain']);
        $account = $this->install->account;
        $server = $account->server;
        $domain = $this->install->domain;

        $this->install->update(['status' => 'installing']);

        $payload = [
            'username' => $account->linux_username,
            'domain' => $domain->domain,
            'install_path' => $this->install->install_path,
            'admin_user' => $this->install->admin_user,
            'admin_password' => $this->adminPassword,
            'admin_email' => $this->install->admin_email,
            'site_title' => $this->install->site_title,
            'db_name' => $this->install->db_name,
            'db_user' => $this->install->db_user,
            'db_password' => $this->dbPassword,
            'db_prefix' => $this->install->table_prefix,
            'locale' => $this->install->locale,
        ];

        $maskedPayload = $payload;
        $maskedPayload['admin_password'] = '***REDACTED***';
        $maskedPayload['db_password'] = '***REDACTED***';

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'wordpress.install',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $maskedPayload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        try {
            $response = $client->execute(
                server: $server,
                action: 'wordpress.install',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            if ($response['success']) {
                $this->install->update(['status' => 'installed']);

                // Ensure domain is configured with WordPress profile and reload Nginx
                $domain->update(['app_profile' => AppProfile::WORDPRESS]);
                WriteVhostJob::dispatch($domain, $this->dryRun);

                $agentJob->markCompleted($response['data']);
            } else {
                $this->install->update(['status' => 'failed']);
                $agentJob->markFailed($response['error'] ?? 'WordPress installation failed');
            }
        } catch (Throwable $e) {
            $this->install->update(['status' => 'failed']);
            $agentJob->markFailed($e->getMessage());

            throw $e;
        }
    }
}
