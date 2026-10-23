<?php

namespace App\Livewire\Dashboard;

use App\Enums\ServerStatus;
use App\Models\Account;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DashboardOverview extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isAdmin()) {
            return $this->renderAdminDashboard($user);
        }

        if ($user->isReseller()) {
            return $this->renderResellerDashboard($user);
        }

        return $this->renderCustomerDashboard($user);
    }

    protected function renderCustomerDashboard(User $user): View
    {
        /** @var Account|null $account */
        $account = $user->accounts()
            ->with(['package', 'domains.certificate', 'databases'])
            ->first();

        $metrics = null;
        if ($account instanceof Account) {
            $pkg = $account->package;

            $storageUsedMb = (float) ($account->current_storage_mb ?? 0);
            $storageMaxMb = (float) max(1, $pkg->storage_mb);
            $storagePct = min(100, ($storageUsedMb / $storageMaxMb) * 100);

            $bwUsedBytes = (int) ($account->current_bandwidth_bytes ?? 0);
            $bwUsedMb = $bwUsedBytes / (1024 * 1024);
            $bwMaxMb = (float) max(1, $pkg->bandwidth_gb * 1024);
            $bwPct = min(100, ($bwUsedMb / $bwMaxMb) * 100);

            $inodesUsed = (int) ($account->current_inodes ?? 0);
            $inodesMax = (int) max(1, $pkg->inodes);
            $inodesPct = min(100, ($inodesUsed / $inodesMax) * 100);

            $sitesCount = $account->domains->count();
            $sitesMax = (int) $pkg->max_websites;
            $sitesPct = $sitesMax > 0 ? min(100, ($sitesCount / $sitesMax) * 100) : 0;

            $dbCount = $account->databases->count();
            $dbMax = (int) $pkg->max_databases;
            $dbPct = $dbMax > 0 ? min(100, ($dbCount / $dbMax) * 100) : 0;

            $metrics = [
                'storage' => [
                    'used_str' => $storageUsedMb >= 1024 ? sprintf('%.1f GB', $storageUsedMb / 1024) : sprintf('%.0f MB', $storageUsedMb),
                    'max_str' => $storageMaxMb >= 1024 ? sprintf('%.1f GB', $storageMaxMb / 1024) : sprintf('%.0f MB', $storageMaxMb),
                    'pct' => $storagePct,
                ],
                'bandwidth' => [
                    'used_str' => $bwUsedMb >= 1024 ? sprintf('%.2f GB', $bwUsedMb / 1024) : sprintf('%.1f MB', $bwUsedMb),
                    'max_str' => sprintf('%.0f GB', $pkg->bandwidth_gb),
                    'pct' => $bwPct,
                ],
                'inodes' => [
                    'used_str' => number_format($inodesUsed),
                    'max_str' => number_format($inodesMax),
                    'pct' => $inodesPct,
                ],
                'sites' => [
                    'count' => $sitesCount,
                    'max' => $sitesMax,
                    'pct' => $sitesPct,
                ],
                'databases' => [
                    'count' => $dbCount,
                    'max' => $dbMax,
                    'pct' => $dbPct,
                ],
            ];
        }

        return view('livewire.dashboard.customer-dashboard', [
            'user' => $user,
            'account' => $account,
            'metrics' => $metrics,
        ]);
    }

    protected function renderAdminDashboard(User $user): View
    {
        $totalServers = Server::count();
        $activeServers = Server::where('status', ServerStatus::ACTIVE)->count();
        $drainingServers = Server::where('status', ServerStatus::DRAINING)->count();
        $totalAccounts = Account::count();
        $totalDomains = Domain::count();
        $totalPackages = Package::count();

        $servers = Server::withCount('accounts')->take(6)->get();

        return view('livewire.dashboard.admin-dashboard', [
            'user' => $user,
            'totalServers' => $totalServers,
            'activeServers' => $activeServers,
            'drainingServers' => $drainingServers,
            'totalAccounts' => $totalAccounts,
            'totalDomains' => $totalDomains,
            'totalPackages' => $totalPackages,
            'servers' => $servers,
        ]);
    }

    protected function renderResellerDashboard(User $user): View
    {
        $profile = $user->resellerProfile;
        $customersCount = $user->children()->count();

        $childIds = $user->children()->pluck('id');
        $accounts = Account::whereIn('user_id', $childIds)->with('package')->get();

        $accountsCount = $accounts->count();
        $maxAccounts = $profile !== null ? $profile->max_accounts : 10;
        $accPct = min(100, ($accountsCount / max(1, $maxAccounts)) * 100);

        $storageUsedMb = $accounts->sum(fn ($a) => (float) $a->current_storage_mb);
        $maxStorageMb = $profile !== null ? $profile->max_storage_mb : 51200;
        $storagePct = min(100, ($storageUsedMb / max(1, $maxStorageMb)) * 100);

        $bwUsedBytes = $accounts->sum(fn ($a) => (int) $a->current_bandwidth_bytes);
        $bwUsedMb = $bwUsedBytes / (1024 * 1024);
        $maxBwGb = $profile !== null ? $profile->max_bandwidth_gb : 500;
        $bwPct = min(100, ($bwUsedMb / max(1, $maxBwGb * 1024)) * 100);

        return view('livewire.dashboard.reseller-dashboard', [
            'user' => $user,
            'profile' => $profile,
            'customersCount' => $customersCount,
            'accountsCount' => $accountsCount,
            'maxAccounts' => $maxAccounts,
            'accPct' => $accPct,
            'storageUsedMb' => $storageUsedMb,
            'maxStorageMb' => $maxStorageMb,
            'storagePct' => $storagePct,
            'bwUsedMb' => $bwUsedMb,
            'maxBwGb' => $maxBwGb,
            'bwPct' => $bwPct,
        ]);
    }
}
