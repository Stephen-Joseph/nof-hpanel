<?php

namespace App\Livewire\Domains;

use App\Enums\AppProfile;
use App\Jobs\SwitchDomainPhpVersionJob;
use App\Models\AgentJob;
use App\Models\Domain;
use App\Models\Server;
use App\Services\AgentClient;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class PhpManager extends Component
{
    public Domain $domain;

    public string $php_version = '8.3';

    public string $app_profile = 'php-generic';

    public string $memory_limit = '256M';

    public string $upload_max_filesize = '32M';

    public string $post_max_size = '32M';

    public int $max_execution_time = 60;

    public string $display_errors = 'off';

    /**
     * @var list<string>
     */
    public array $allowedVersions = ['8.1', '8.2', '8.3', '8.4'];

    public int $packageMaxMemory = 256;

    public int $packageWorkers = 5;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $fpmStatus = null;

    public bool $hasLoadedStatus = false;

    public bool $isLaravelDetected = false;

    public string $laravelMinPhpVersion = '';

    public string $laravelRoot = '';

    public ?string $lastJobId = null;

    public ?string $actionOutput = null;

    public ?string $actionError = null;

    public bool $showMigrateModal = false;

    public bool $showingLogs = false;

    public string $phpErrorLog = '';

    public string $laravelLog = '';

    public function mount(Domain $domain): void
    {
        $this->authorize('view', $domain);

        $this->domain = $domain->load(['account.package', 'account.server']);
        $package = $this->domain->account->package;

        $this->php_version = $this->domain->php_version;
        $this->app_profile = $this->domain->app_profile->value;
        $this->packageMaxMemory = $package->php_memory_mb ?? 256;
        $this->packageWorkers = $package->php_workers ?? 5;

        $packageVersions = ! empty($package->allowed_php_versions)
            ? array_values(array_map('strval', $package->allowed_php_versions))
            : ['8.1', '8.2', '8.3', '8.4'];

        $this->allowedVersions = $packageVersions;

        $client = app(AgentClient::class);
        $server = $this->domain->account->server;

        if ($server) {
            try {
                $verRes = $client->execute(
                    server: $server,
                    action: 'php.list_versions',
                    payload: [],
                    dryRun: false
                );
                if ($verRes['success'] && ! empty($verRes['data']['installed_versions'])) {
                    $installed = array_values(array_map('strval', (array) $verRes['data']['installed_versions']));
                    $intersected = array_values(array_intersect($installed, $packageVersions));
                    if (! empty($intersected)) {
                        $this->allowedVersions = $intersected;
                    } else {
                        $this->allowedVersions = $installed;
                    }
                }
            } catch (\Throwable) {
                // Keep package allowed versions fallback
            }

            try {
                $detectRes = $client->execute(
                    server: $server,
                    action: 'laravel.detect',
                    payload: [
                        'domain' => $this->domain->domain,
                        'username' => $this->domain->account->linux_username,
                    ],
                    dryRun: false
                );
                if ($detectRes['success']) {
                    $this->isLaravelDetected = (bool) ($detectRes['data']['detected'] ?? false);
                    $this->laravelMinPhpVersion = (string) ($detectRes['data']['min_php_version'] ?? '');
                    $this->laravelRoot = (string) ($detectRes['data']['laravel_root'] ?? '');
                }
            } catch (\Throwable) {
                // Ignore detection failure on mount
            }
        }

        if (! in_array($this->php_version, $this->allowedVersions, true)) {
            $this->allowedVersions[] = $this->php_version;
        }

        $ini = $this->domain->getPhpIni();
        $this->memory_limit = (string) ($ini['memory_limit'] ?? '256M');
        $this->upload_max_filesize = (string) ($ini['upload_max_filesize'] ?? '32M');
        $this->post_max_size = (string) ($ini['post_max_size'] ?? '32M');
        $this->max_execution_time = (int) ($ini['max_execution_time'] ?? 60);
        $this->display_errors = (string) ($ini['display_errors'] ?? 'off');

        $latestJob = AgentJob::where('account_id', $this->domain->account_id)
            ->where('action', 'php.set_version')
            ->latest('created_at')
            ->first();
        $this->lastJobId = $latestJob?->id;
    }

    public function saveSettings(): void
    {
        $this->authorize('update', $this->domain);
        $this->actionOutput = null;
        $this->actionError = null;

        $this->validate([
            'app_profile' => ['required', Rule::in(['php-generic', 'laravel', 'wordpress', 'static-spa'])],
            'php_version' => ['required', Rule::in($this->allowedVersions)],
            'memory_limit' => ['required', Rule::in(['64M', '128M', '256M', '512M', '1024M'])],
            'upload_max_filesize' => ['required', Rule::in(['10M', '32M', '64M', '128M', '256M'])],
            'post_max_size' => ['required', Rule::in(['10M', '32M', '64M', '128M', '256M'])],
            'max_execution_time' => ['required', 'integer', 'between:10,600'],
            'display_errors' => ['required', Rule::in(['off', 'on'])],
        ]);

        // Enforce package maximum memory limit
        $requestedMB = (int) filter_var($this->memory_limit, FILTER_SANITIZE_NUMBER_INT);
        if ($requestedMB > $this->packageMaxMemory) {
            $this->addError('memory_limit', "Requested memory limit ({$this->memory_limit}) exceeds your package allowance of {$this->packageMaxMemory}M.");

            return;
        }

        $this->domain->update([
            'app_profile' => AppProfile::from($this->app_profile),
        ]);

        $iniSettings = [
            'memory_limit' => $this->memory_limit,
            'upload_max_filesize' => $this->upload_max_filesize,
            'post_max_size' => $this->post_max_size,
            'max_execution_time' => $this->max_execution_time,
            'display_errors' => $this->display_errors,
        ];

        SwitchDomainPhpVersionJob::dispatch(
            domain: $this->domain,
            newVersion: $this->php_version,
            iniSettings: $iniSettings
        );

        $latestJob = AgentJob::where('account_id', $this->domain->account_id)
            ->where('action', 'php.set_version')
            ->latest('created_at')
            ->first();
        $this->lastJobId = $latestJob?->id;

        session()->flash('status', "PHP version updated to {$this->php_version} and pool directives deployed.");
    }

    public function switchToRequiredVersion(): void
    {
        $this->authorize('update', $this->domain);

        if ($this->laravelMinPhpVersion === '') {
            return;
        }

        if (! in_array($this->laravelMinPhpVersion, $this->allowedVersions, true)) {
            $this->actionError = "PHP {$this->laravelMinPhpVersion} is not installed or not allowed on this package.";

            return;
        }

        $this->php_version = $this->laravelMinPhpVersion;
        $this->saveSettings();
    }

    public function fixLaravelPermissions(AgentClient $client): void
    {
        $this->authorize('update', $this->domain);
        $this->actionOutput = null;
        $this->actionError = null;

        try {
            $res = $client->execute(
                server: $this->domain->account->server,
                action: 'laravel.fix_permissions',
                payload: [
                    'domain' => $this->domain->domain,
                    'username' => $this->domain->account->linux_username,
                ],
                dryRun: false
            );

            if ($res['success']) {
                $this->actionOutput = 'Laravel storage and bootstrap/cache permissions fixed successfully.';
            } else {
                $this->actionError = (string) ($res['error'] ?? 'Failed to fix Laravel permissions.');
            }
        } catch (\Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function clearLaravelCaches(AgentClient $client): void
    {
        $this->authorize('update', $this->domain);
        $this->runArtisan($client, 'optimize:clear');
    }

    public function openMigrateModal(): void
    {
        $this->authorize('update', $this->domain);
        $this->showMigrateModal = true;
    }

    public function closeMigrateModal(): void
    {
        $this->showMigrateModal = false;
    }

    public function runLaravelMigrations(AgentClient $client): void
    {
        $this->authorize('update', $this->domain);
        $this->showMigrateModal = false;
        $this->runArtisan($client, 'migrate --force');
    }

    protected function runArtisan(AgentClient $client, string $command): void
    {
        $this->actionOutput = null;
        $this->actionError = null;

        try {
            $res = $client->execute(
                server: $this->domain->account->server,
                action: 'laravel.artisan',
                payload: [
                    'domain' => $this->domain->domain,
                    'username' => $this->domain->account->linux_username,
                    'php_version' => $this->php_version,
                    'command' => $command,
                ],
                dryRun: false
            );

            $out = (string) ($res['data']['output'] ?? '');
            if ($res['success']) {
                $this->actionOutput = $out !== '' ? $out : "Executed 'php artisan {$command}' successfully.";
            } else {
                $this->actionError = (string) ($res['error'] ?? "Artisan '{$command}' failed.").($out !== '' ? "\n".$out : '');
            }
        } catch (\Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function loadErrorLogs(AgentClient $client): void
    {
        $this->authorize('view', $this->domain);
        $this->showingLogs = true;
        $username = $this->domain->account->linux_username;
        $server = $this->domain->account->server;

        $this->phpErrorLog = $this->readLast100Lines($client, $server, $username, "/home/{$username}/logs/php-error.log");
        $laravelRoot = $this->laravelRoot !== '' ? $this->laravelRoot : "/home/{$username}/public_html";
        $this->laravelLog = $this->readLast100Lines($client, $server, $username, "{$laravelRoot}/storage/logs/laravel.log");
    }

    protected function readLast100Lines(AgentClient $client, Server $server, string $username, string $path): string
    {
        try {
            $res = $client->execute(
                server: $server,
                action: 'fs.read_text',
                payload: [
                    'username' => $username,
                    'path' => $path,
                ],
                dryRun: false
            );
            if ($res['success']) {
                $content = (string) ($res['data']['content'] ?? '');
                $lines = explode("\n", $content);

                return implode("\n", array_slice($lines, -100));
            }

            return (string) ($res['error'] ?? 'Log file not found.');
        } catch (\Throwable $e) {
            return 'Unable to read log: '.$e->getMessage();
        }
    }

    public function checkFpmStatus(AgentClient $client): void
    {
        $this->hasLoadedStatus = true;

        try {
            $response = $client->execute(
                server: $this->domain->account->server,
                action: 'php.fpm_status',
                payload: [
                    'username' => $this->domain->account->linux_username,
                    'version' => $this->php_version,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $this->fpmStatus = $response['data'];
            }
        } catch (\Throwable $e) {
            $this->fpmStatus = [
                'error' => $e->getMessage(),
            ];
        }
    }

    public function render(): View
    {
        $latestJob = $this->lastJobId
            ? AgentJob::with('provisioningSteps')->find($this->lastJobId)
            : null;

        return view('livewire.domains.php-manager', [
            'latestJob' => $latestJob,
        ]);
    }
}
