<?php

namespace App\Livewire\Packages;

use App\Models\Package;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class PackageForm extends Component
{
    public ?Package $package = null;

    public bool $isEditing = false;

    public string $name = '';

    public int $storage_mb = 10240;

    public int $inodes = 100000;

    public int $bandwidth_gb = 100;

    public int $max_websites = 1;

    public int $max_subdomains = 5;

    public int $max_databases = 5;

    public int $max_db_users = 5;

    public int $max_ftp_accounts = 5;

    public int $max_cron_jobs = 5;

    public int $php_workers = 5;

    public int $php_memory_mb = 256;

    /** @var list<string> */
    public array $allowed_php_versions = ['8.2', '8.3', '8.4'];

    public int $backups_retained = 3;

    public bool $ssh_enabled = false;

    public bool $git_enabled = false;

    /**
     * @var list<string>
     */
    public array $availablePhpVersions = ['8.1', '8.2', '8.3', '8.4'];

    public function mount(?Package $package = null): void
    {
        if ($package && $package->exists) {
            $this->authorize('update', $package);
            $this->package = $package;
            $this->isEditing = true;

            $this->name = $package->name;
            $this->storage_mb = $package->storage_mb;
            $this->inodes = $package->inodes;
            $this->bandwidth_gb = $package->bandwidth_gb;
            $this->max_websites = $package->max_websites;
            $this->max_subdomains = $package->max_subdomains;
            $this->max_databases = $package->max_databases;
            $this->max_db_users = $package->max_db_users;
            $this->max_ftp_accounts = $package->max_ftp_accounts;
            $this->max_cron_jobs = $package->max_cron_jobs;
            $this->php_workers = $package->php_workers;
            $this->php_memory_mb = $package->php_memory_mb;
            $this->allowed_php_versions = $package->allowed_php_versions;
            $this->backups_retained = $package->backups_retained;
            $this->ssh_enabled = $package->ssh_enabled;
            $this->git_enabled = $package->git_enabled;
        } else {
            $this->authorize('create', Package::class);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'storage_mb' => ['required', 'integer', 'min:100'],
            'inodes' => ['required', 'integer', 'min:1000'],
            'bandwidth_gb' => ['required', 'integer', 'min:1'],
            'max_websites' => ['required', 'integer', 'min:1'],
            'max_subdomains' => ['required', 'integer', 'min:0'],
            'max_databases' => ['required', 'integer', 'min:0'],
            'max_db_users' => ['required', 'integer', 'min:0'],
            'max_ftp_accounts' => ['required', 'integer', 'min:0'],
            'max_cron_jobs' => ['required', 'integer', 'min:0'],
            'php_workers' => ['required', 'integer', 'min:1', 'max:100'],
            'php_memory_mb' => ['required', 'integer', 'min:64', 'max:4096'],
            'allowed_php_versions' => ['required', 'array', 'min:1'],
            'allowed_php_versions.*' => ['in:8.1,8.2,8.3,8.4'],
            'backups_retained' => ['required', 'integer', 'min:0', 'max:60'],
            'ssh_enabled' => ['boolean'],
            'git_enabled' => ['boolean'],
        ];
    }

    public function save(AuditService $audit): RedirectResponse
    {
        $validated = $this->validate();

        /** @var User $user */
        $user = Auth::user();

        if ($this->isEditing && $this->package) {
            $this->authorize('update', $this->package);
            $oldValues = $this->package->toArray();
            $this->package->update($validated);
            $audit->log('package.updated', $this->package, $oldValues, $this->package->toArray(), $user);
            session()->flash('status', 'Package successfully updated.');
        } else {
            $this->authorize('create', Package::class);
            $data = $validated;
            $data['reseller_owner_id'] = $user->isReseller() ? $user->id : null;

            $package = Package::create($data);
            $audit->log('package.created', $package, null, $package->toArray(), $user);
            session()->flash('status', 'Package successfully created.');
        }

        return redirect()->route('packages.index');
    }

    public function render(): View
    {
        return view('livewire.packages.package-form');
    }
}
