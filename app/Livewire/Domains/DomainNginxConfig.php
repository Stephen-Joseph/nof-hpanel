<?php

namespace App\Livewire\Domains;

use App\Models\Domain;
use App\Models\User;
use App\Services\AgentClient;
use App\Services\AuditService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Component;

class DomainNginxConfig extends Component
{
    public Domain $domain;

    /**
     * @var array<int, array{line: int, text: string, locked: bool}>
     */
    public array $lines = [];

    public string $fullContent = '';

    public string $customBlock = '';

    /**
     * @var array<int, string>
     */
    public array $backups = [];

    public string $selectedBackupId = '';

    public bool $fileExists = false;

    public bool $showSaveModal = false;

    public bool $advancedMode = false;

    public string $advancedConfirmDomain = '';

    public ?string $nginxTestOutput = null;

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(Domain $domain): void
    {
        $this->authorize('update', $domain);

        $this->domain = $domain->load([
            'account.server',
            'account.package',
            'account.user',
            'certificate',
            'redirects',
            'protectedDirectories',
        ]);

        $this->loadConfig(app(AgentClient::class));
    }

    public function loadConfig(AgentClient $client): void
    {
        $server = $this->domain->account->server;
        if (! $server) {
            $this->errorMessage = 'No server is associated with this account.';

            return;
        }

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.domain.read',
                payload: ['domain' => $this->domain->domain],
                dryRun: false
            );

            if ($res['success']) {
                $data = $res['data'] ?? [];
                $this->fileExists = (bool) ($data['exists'] ?? false);
                $this->fullContent = (string) ($data['content'] ?? '');
                $this->customBlock = (string) ($data['custom_block'] ?? '');
                $this->lines = (array) ($data['lines'] ?? []);
                $this->backups = array_values((array) ($data['backups'] ?? []));
                if ($this->selectedBackupId === '' && ! empty($this->backups)) {
                    $this->selectedBackupId = (string) $this->backups[0];
                }
            } else {
                $this->errorMessage = (string) ($res['error'] ?? 'Failed to read Nginx configuration.');
            }
        } catch (\Throwable $e) {
            $this->errorMessage = 'Failed to communicate with server agent: '.$e->getMessage();
        }
    }

    public function openSaveModal(): void
    {
        $this->authorize('update', $this->domain);
        $this->showSaveModal = true;
    }

    public function closeSaveModal(): void
    {
        $this->showSaveModal = false;
    }

    public function saveConfig(AgentClient $client, AuditService $audit): void
    {
        $this->authorize('update', $this->domain);
        $this->showSaveModal = false;
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->nginxTestOutput = null;

        /** @var User $user */
        $user = Auth::user();

        $rateKey = 'nginx-config-save:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 20)) {
            $seconds = RateLimiter::availableIn($rateKey);
            $this->errorMessage = "Rate limit exceeded (max 20 config saves per hour). Try again in {$seconds} seconds.";

            return;
        }
        RateLimiter::hit($rateKey, 3600);

        if ($this->advancedMode) {
            if (! $user->isAdmin()) {
                abort(403, 'Advanced mode is restricted to administrators.');
            }
            if (strtolower(trim($this->advancedConfirmDomain)) !== strtolower($this->domain->domain)) {
                $this->errorMessage = 'Please type the exact domain name to confirm Advanced Mode edits.';

                return;
            }
        }

        $panelDomain = strtolower((string) config('hpanel.panel_domain', ''));
        if ($panelDomain !== '' && strtolower($this->domain->domain) === $panelDomain) {
            $this->errorMessage = 'The panel vhost cannot be modified from this editor.';

            return;
        }

        $server = $this->domain->account->server;
        if (! $server) {
            $this->errorMessage = 'No server is associated with this account.';

            return;
        }

        $maxBodyMb = (int) ($this->domain->account->package->max_upload_mb ?? 128);
        if ($maxBodyMb <= 0) {
            $maxBodyMb = 128;
        }

        $payload = [
            'domain' => $this->domain->domain,
            'username' => $this->domain->account->linux_username,
            'custom_block' => $this->customBlock,
            'max_body_size_mb' => $maxBodyMb,
            'advanced_mode' => $this->advancedMode && $user->isAdmin(),
            'full_content' => $this->advancedMode && $user->isAdmin() ? $this->fullContent : '',
        ];

        $resultStatus = 'failed';
        $errorDetail = null;

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.domain.write',
                payload: $payload,
                dryRun: false
            );

            $this->nginxTestOutput = (string) ($res['data']['nginx_test_output'] ?? '');

            if ($res['success']) {
                $resultStatus = 'success';
                $this->statusMessage = 'Nginx configuration validated with nginx -t, saved, and reloaded.';
                $this->loadConfig($client);
            } else {
                $errorDetail = (string) ($res['error'] ?? 'Nginx configuration validation failed.');
                $this->errorMessage = $errorDetail;
            }
        } catch (\Throwable $e) {
            $errorDetail = $e->getMessage();
            $this->errorMessage = 'Agent error: '.$errorDetail;
        }

        // Never store config content in audit logs
        $audit->log(
            action: 'domain.nginx.save',
            target: $this->domain,
            oldValues: null,
            newValues: [
                'domain' => $this->domain->domain,
                'advanced_mode' => $this->advancedMode && $user->isAdmin(),
                'result' => $resultStatus,
                'error' => $errorDetail,
            ],
            actor: $user
        );
    }

    public function restoreBackup(AgentClient $client, AuditService $audit): void
    {
        $this->authorize('update', $this->domain);
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->nginxTestOutput = null;

        if ($this->selectedBackupId === '') {
            $this->errorMessage = 'Please select a backup to restore.';

            return;
        }

        $server = $this->domain->account->server;
        if (! $server) {
            $this->errorMessage = 'No server is associated with this account.';

            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $resultStatus = 'failed';

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.domain.restore',
                payload: [
                    'domain' => $this->domain->domain,
                    'backup_id' => $this->selectedBackupId,
                ],
                dryRun: false
            );

            $this->nginxTestOutput = (string) ($res['data']['nginx_test_output'] ?? '');

            if ($res['success']) {
                $resultStatus = 'success';
                $this->statusMessage = "Restored Nginx configuration from backup '{$this->selectedBackupId}'.";
                $this->loadConfig($client);
            } else {
                $this->errorMessage = (string) ($res['error'] ?? 'Failed to restore backup.');
            }
        } catch (\Throwable $e) {
            $this->errorMessage = 'Agent error: '.$e->getMessage();
        }

        $audit->log(
            action: 'domain.nginx.restore',
            target: $this->domain,
            oldValues: null,
            newValues: [
                'domain' => $this->domain->domain,
                'backup_id' => $this->selectedBackupId,
                'result' => $resultStatus,
            ],
            actor: $user
        );
    }

    public function resetToDefault(AgentClient $client, AuditService $audit): void
    {
        $this->authorize('update', $this->domain);
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->nginxTestOutput = null;

        $server = $this->domain->account->server;
        if (! $server) {
            $this->errorMessage = 'No server is associated with this account.';

            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $resultStatus = 'failed';
        $cert = $this->domain->certificate;

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.domain.reset',
                payload: [
                    'username' => $this->domain->account->linux_username,
                    'domain' => $this->domain->domain,
                    'document_root' => $this->domain->document_root,
                    'app_profile' => $this->domain->app_profile->value,
                    'php_version' => $this->domain->php_version,
                    'force_https' => (bool) $this->domain->force_https,
                    'ssl_enabled' => $cert && $cert->status->value === 'active',
                    'ssl_cert_path' => $cert?->cert_path ?? '',
                    'ssl_key_path' => $cert?->key_path ?? '',
                    'hsts_enabled' => (bool) ($cert?->hsts_enabled ?? false),
                    'www_redirect' => $this->domain->www_redirect->value ?? 'none',
                ],
                dryRun: false
            );

            $this->nginxTestOutput = (string) ($res['data']['nginx_test_output'] ?? '');

            if ($res['success']) {
                $resultStatus = 'success';
                $this->statusMessage = 'Nginx configuration reset to default template.';
                $this->loadConfig($client);
            } else {
                $this->errorMessage = (string) ($res['error'] ?? 'Failed to reset Nginx configuration.');
            }
        } catch (\Throwable $e) {
            $this->errorMessage = 'Agent error: '.$e->getMessage();
        }

        $audit->log(
            action: 'domain.nginx.reset',
            target: $this->domain,
            oldValues: null,
            newValues: [
                'domain' => $this->domain->domain,
                'result' => $resultStatus,
            ],
            actor: $user
        );
    }

    public function render(): View
    {
        return view('livewire.domains.domain-nginx-config');
    }
}
