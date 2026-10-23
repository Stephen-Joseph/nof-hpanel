<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\Domain;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class WriteVhostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Domain $domain,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->domain->loadMissing(['account.server', 'account.package', 'redirects', 'certificate', 'protectedDirectories']);
        $account = $this->domain->account;
        $server = $account->server;

        $redirects = $this->domain->redirects()
            ->where('is_active', true)
            ->get()
            ->map(fn ($r) => [
                'source' => $r->source_path,
                'target' => $r->target_url,
                'code' => $r->status_code,
            ])
            ->toArray();

        $protectedDirs = $this->domain->protectedDirectories()
            ->where('is_active', true)
            ->get()
            ->map(fn ($p) => [
                'path' => $p->path,
                'realm' => $p->realm,
                'htpasswd_path' => "/home/{$account->linux_username}/.htpasswds/{$p->htpasswd_filename}",
            ])
            ->toArray();

        $aliases = [];
        if ($this->domain->www_redirect->value === 'none') {
            $aliases[] = 'www.'.$this->domain->domain;
        }

        $hasSsl = $this->domain->hasActiveCertificate();

        $payload = [
            'username' => $account->linux_username,
            'domain' => $this->domain->domain,
            'aliases' => $aliases,
            'document_root' => $this->domain->document_root,
            'app_profile' => $this->domain->app_profile->value,
            'php_version' => $this->domain->php_version,
            'force_https' => $this->domain->force_https,
            'ssl_enabled' => $hasSsl,
            'ssl_cert_path' => $this->domain->certificate->cert_path ?? '',
            'ssl_key_path' => $this->domain->certificate->key_path ?? '',
            'hsts_enabled' => (bool) ($this->domain->certificate->hsts_enabled ?? false),
            'www_redirect' => $this->domain->www_redirect->value,
            'redirects' => $redirects,
            'protected_dirs' => $protectedDirs,
            'max_websites' => $account->package->max_websites,
            'current_website_count' => $account->domains()->count(),
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'vhost.write',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf('Writing virtual host for %s (profile: %s)', $this->domain->domain, $this->domain->app_profile->value),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'vhost.write',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            if ($response['success']) {
                $agentJob->markCompleted($response['data']);
            } else {
                $agentJob->markFailed($response['error'] ?? 'Vhost write failed');
            }
        } catch (Throwable $e) {
            $agentJob->recordLog('Vhost write error: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());

            throw $e;
        }
    }
}
