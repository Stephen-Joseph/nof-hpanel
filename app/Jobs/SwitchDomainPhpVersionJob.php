<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Enums\AppProfile;
use App\Models\AgentJob;
use App\Models\Domain;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SwitchDomainPhpVersionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public ?string $agentJobId = null;

    /**
     * @param  array<string, mixed>  $iniSettings
     */
    public function __construct(
        public Domain $domain,
        public string $newVersion,
        public array $iniSettings = [],
        public bool $dryRun = false
    ) {}

    public function handle(?AgentClient $client = null): void
    {
        $client ??= app(AgentClient::class);
        $this->domain->loadMissing(['account.server', 'account.package', 'certificate', 'redirects', 'protectedDirectories']);
        $account = $this->domain->account;
        $server = $account->server;
        $package = $account->package;

        $previousVersion = $this->domain->php_version;
        $versionChanged = $previousVersion !== $this->newVersion;

        $otherUsesPrevious = $account->domains()
            ->where('id', '!=', $this->domain->id)
            ->where('php_version', $previousVersion)
            ->exists();

        $workers = (int) ($package->php_workers ?? 5);
        $pmMode = $workers > 5 ? 'dynamic' : 'ondemand';

        $cert = $this->domain->certificate;
        $sslEnabled = $cert && $cert->status->value === 'active';

        $payload = [
            'domain' => $this->domain->domain,
            'username' => $account->linux_username,
            'version' => $this->newVersion,
            'previous_version' => $previousVersion,
            'remove_previous_pool' => $versionChanged && ! $otherUsesPrevious,
            'document_root' => $this->domain->document_root,
            'app_profile' => $this->domain->app_profile->value,
            'pm_mode' => $pmMode,
            'max_children' => $workers,
            'max_memory_mb' => (int) ($package->php_memory_mb ?? 256),
            'allow_exec_functions' => (bool) ($package->allow_exec_functions ?? false),
            'settings' => $this->iniSettings,
            'force_https' => (bool) $this->domain->force_https,
            'ssl_enabled' => $sslEnabled,
            'ssl_cert_path' => $cert?->cert_path ?? '',
            'ssl_key_path' => $cert?->key_path ?? '',
            'hsts_enabled' => (bool) ($cert?->hsts_enabled ?? false),
            'www_redirect' => $this->domain->www_redirect->value ?? 'none',
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'php.set_version',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'started_at' => now(),
        ]);

        $this->agentJobId = $agentJob->id;

        $stepDefinitions = [
            'create_pool' => 'Create PHP-FPM pool',
            'test_pool' => 'Test PHP-FPM configuration',
            'reload_fpm' => 'Reload PHP-FPM service',
            'update_vhost' => 'Update Nginx virtual host',
            'test_nginx' => 'Test Nginx configuration',
            'reload_nginx' => 'Reload Nginx service',
            'fix_permissions' => 'Fix Laravel permissions',
        ];

        foreach ($stepDefinitions as $key => $label) {
            $agentJob->createStep($key, $label);
        }

        $agentJob->getStep('create_pool')?->markRunning('Deploying PHP-FPM pool and Nginx vhost');

        try {
            $response = $client->execute(
                server: $server,
                action: 'php.set_version',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] ?? [] as $logLine) {
                $agentJob->recordLog((string) $logLine, 'info');
            }

            if (! $response['success']) {
                $err = (string) ($response['error'] ?? 'Failed to switch PHP version.');
                $stepStates = (array) ($response['data']['steps'] ?? []);
                foreach ($stepDefinitions as $key => $label) {
                    $step = $agentJob->getStep($key);
                    if (! $step) {
                        continue;
                    }
                    if (($stepStates[$key] ?? null) === 'done') {
                        $step->markDone('Completed');
                    } elseif (($stepStates[$key] ?? null) === 'failed') {
                        $step->markFailed($err);
                    }
                }
                if (! $agentJob->provisioningSteps()->where('status', 'failed')->exists()) {
                    $agentJob->getStep('create_pool')?->markFailed($err);
                }
                $agentJob->markFailed($err);

                return;
            }

            foreach (['create_pool', 'test_pool', 'reload_fpm', 'update_vhost', 'test_nginx', 'reload_nginx'] as $key) {
                $agentJob->getStep($key)?->markDone('Completed');
            }

            $updateData = [
                'php_version' => $this->newVersion,
                'php_ini' => $this->iniSettings,
            ];
            if (! empty($response['data']['document_root'])) {
                $updateData['document_root'] = (string) $response['data']['document_root'];
            }
            $this->domain->update($updateData);

            // Run laravel.fix_permissions automatically when profile is Laravel or PHP version changed
            $fixStep = $agentJob->getStep('fix_permissions');
            if ($this->domain->app_profile === AppProfile::LARAVEL || $versionChanged) {
                $fixStep?->markRunning('Applying Laravel storage & bootstrap/cache permissions');
                $fixRes = $client->execute(
                    server: $server,
                    action: 'laravel.fix_permissions',
                    payload: [
                        'domain' => $this->domain->domain,
                        'username' => $account->linux_username,
                    ],
                    dryRun: $this->dryRun
                );

                if ($fixRes['success']) {
                    $fixStep?->markDone('Permissions verified');
                } elseif ($this->domain->app_profile === AppProfile::LARAVEL) {
                    $fixErr = (string) ($fixRes['error'] ?? 'Failed to fix Laravel permissions.');
                    $fixStep?->markFailed($fixErr);
                    $agentJob->markFailed($fixErr);

                    return;
                } else {
                    $fixStep?->markDone('Skipped (non-Laravel site)');
                }
            } else {
                $fixStep?->markDone('Not required');
            }

            $agentJob->markCompleted($response['data'] ?? []);
        } catch (Throwable $e) {
            $err = $e->getMessage();
            $agentJob->provisioningSteps()->where('status', 'running')->first()?->markFailed($err);
            $agentJob->markFailed($err);
        }
    }
}
