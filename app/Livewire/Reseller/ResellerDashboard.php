<?php

namespace App\Livewire\Reseller;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Package;
use App\Models\ResellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class ResellerDashboard extends Component
{
    public User $reseller;

    public ResellerProfile $profile;

    public string $activeTab = 'overview';

    // Add Customer fields
    public bool $showCustomerModal = false;

    public string $customer_name = '';

    public string $customer_email = '';

    public string $customer_password = '';

    // Create Package fields
    public bool $showPackageModal = false;

    public string $pkg_name = '';

    public int $pkg_storage_mb = 5120;

    public int $pkg_inodes = 50000;

    public int $pkg_bandwidth_gb = 50;

    public int $pkg_max_websites = 1;

    public int $pkg_max_databases = 2;

    public int $pkg_php_workers = 3;

    // White-Label Branding fields
    public string $company_name = '';

    public string $custom_domain = '';

    public string $support_email = '';

    public function mount(): void
    {
        /** @var User $currentUser */
        $currentUser = Auth::user();

        if (! $currentUser->isReseller() && ! $currentUser->isAdmin()) {
            abort(403, 'Unauthorized access to reseller management.');
        }

        $this->reseller = $currentUser;

        // Auto-provision profile if none exists yet
        $this->profile = $this->reseller->resellerProfile ?? ResellerProfile::create([
            'user_id' => $this->reseller->id,
            'max_accounts' => 50,
            'max_storage_mb' => 102400,
            'max_bandwidth_gb' => 1000,
            'company_name' => $this->reseller->name.' Hosting',
        ]);

        $this->company_name = $this->profile->company_name;
        $this->custom_domain = $this->profile->custom_domain ?? '';
        $this->support_email = $this->profile->support_email ?? $this->reseller->email;
    }

    public function addCustomer(): void
    {
        $this->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'customer_password' => ['required', 'string', 'min:8'],
        ]);

        if (! $this->profile->canCreateAccount()) {
            session()->flash('error', 'Account creation pool limit reached. Contact panel administrator to expand your quota.');

            return;
        }

        User::create([
            'name' => trim($this->customer_name),
            'email' => trim($this->customer_email),
            'password' => Hash::make($this->customer_password),
            'role' => UserRole::CUSTOMER,
            'parent_id' => $this->reseller->id,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->showCustomerModal = false;
        $this->customer_name = '';
        $this->customer_email = '';
        $this->customer_password = '';

        session()->flash('success', 'Customer account created successfully.');
    }

    public function toggleCustomerStatus(int $customerId): void
    {
        /** @var User $customer */
        $customer = User::where('parent_id', $this->reseller->id)->findOrFail($customerId);

        $newStatus = $customer->status === UserStatus::ACTIVE ? UserStatus::SUSPENDED : UserStatus::ACTIVE;
        $customer->update(['status' => $newStatus]);

        session()->flash('success', "Customer status updated to {$newStatus->value}.");
    }

    public function createPackage(): void
    {
        $this->validate([
            'pkg_name' => ['required', 'string', 'max:64'],
            'pkg_storage_mb' => ['required', 'integer', 'min:100', 'max:'.$this->profile->max_storage_mb],
            'pkg_inodes' => ['required', 'integer', 'min:1000'],
            'pkg_bandwidth_gb' => ['required', 'integer', 'min:1', 'max:'.$this->profile->max_bandwidth_gb],
            'pkg_max_websites' => ['required', 'integer', 'min:1', 'max:20'],
            'pkg_max_databases' => ['required', 'integer', 'min:0', 'max:20'],
            'pkg_php_workers' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        Package::create([
            'name' => trim($this->pkg_name),
            'storage_mb' => $this->pkg_storage_mb,
            'inodes' => $this->pkg_inodes,
            'bandwidth_gb' => $this->pkg_bandwidth_gb,
            'max_websites' => $this->pkg_max_websites,
            'max_databases' => $this->pkg_max_databases,
            'max_subdomains' => 5,
            'max_db_users' => 5,
            'max_ftp_accounts' => 5,
            'max_cron_jobs' => 5,
            'php_workers' => $this->pkg_php_workers,
            'php_memory_mb' => 256,
            'allowed_php_versions' => ['8.3'],
            'backups_retained' => 3,
            'ssh_enabled' => false,
            'git_enabled' => true,
            'reseller_owner_id' => $this->reseller->id,
        ]);

        $this->showPackageModal = false;
        $this->pkg_name = '';

        session()->flash('success', 'Hosting package created successfully.');
    }

    public function saveBranding(): void
    {
        $this->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'custom_domain' => ['nullable', 'string', 'max:255', Rule::unique('reseller_profiles', 'custom_domain')->ignore($this->profile->id)],
            'support_email' => ['nullable', 'email', 'max:255'],
        ]);

        $this->profile->update([
            'company_name' => trim($this->company_name),
            'custom_domain' => $this->custom_domain ? trim($this->custom_domain) : null,
            'support_email' => $this->support_email ? trim($this->support_email) : null,
        ]);

        session()->flash('success', 'White-label branding settings updated.');
    }

    public function render(): View
    {
        $customers = User::where('parent_id', $this->reseller->id)
            ->withCount('accounts')
            ->latest()
            ->get();

        $packages = Package::where('reseller_owner_id', $this->reseller->id)
            ->withCount('accounts')
            ->latest()
            ->get();

        return view('livewire.reseller.reseller-dashboard', [
            'customers' => $customers,
            'packages' => $packages,
            'usedAccounts' => $this->profile->usedAccountsCount(),
            'usedStorage' => $this->profile->usedStorageMb(),
            'usedBandwidth' => $this->profile->usedBandwidthGb(),
        ]);
    }
}
