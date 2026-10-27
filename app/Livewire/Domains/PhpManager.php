<?php

namespace App\Livewire\Domains;

use App\Enums\AppProfile;
use App\Jobs\SwitchDomainPhpVersionJob;
use App\Models\Domain;
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

    public function mount(Domain $domain): void
    {
        $this->authorize('view', $domain);

        $this->domain = $domain->load(['account.package', 'account.server']);
        $package = $this->domain->account->package;

        $this->php_version = $this->domain->php_version;
        $this->app_profile = $this->domain->app_profile->value;
        $this->packageMaxMemory = $package->php_memory_mb ?? 256;
        $this->packageWorkers = $package->php_workers ?? 5;

        if (! empty($package->allowed_php_versions)) {
            $this->allowedVersions = $package->allowed_php_versions;
        }

        $ini = $this->domain->getPhpIni();
        $this->memory_limit = (string) ($ini['memory_limit'] ?? '256M');
        $this->upload_max_filesize = (string) ($ini['upload_max_filesize'] ?? '32M');
        $this->post_max_size = (string) ($ini['post_max_size'] ?? '32M');
        $this->max_execution_time = (int) ($ini['max_execution_time'] ?? 60);
        $this->display_errors = (string) ($ini['display_errors'] ?? 'off');
    }

    public function saveSettings(): void
    {
        $this->authorize('update', $this->domain);

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

        session()->flash('status', "PHP version updated to {$this->php_version} and pool directives deployed.");
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
        return view('livewire.domains.php-manager');
    }
}
