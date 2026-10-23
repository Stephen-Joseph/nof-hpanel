<?php

namespace App\Livewire\Navigation;

use App\Models\Account;
use App\Models\Domain;
use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class GlobalSearch extends Component
{
    public bool $isOpen = false;

    public string $query = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $results = [];

    /**
     * @var array<string, string>
     */
    protected $listeners = [
        'open-global-search' => 'open',
    ];

    public function open(): void
    {
        $this->isOpen = true;
        $this->query = '';
        $this->results = [];
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->query = '';
        $this->results = [];
    }

    public function updatedQuery(): void
    {
        $trimmed = trim($this->query);

        if (strlen($trimmed) < 2) {
            $this->results = [];

            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $items = collect();

        // 1. Navigation items matching query
        $navItems = $this->getNavigationItems($user);
        foreach ($navItems as $nav) {
            if (str_contains(strtolower($nav['title']), strtolower($trimmed)) || str_contains(strtolower($nav['keywords'] ?? ''), strtolower($trimmed))) {
                $items->push([
                    'type' => 'Navigation',
                    'title' => $nav['title'],
                    'subtitle' => $nav['subtitle'],
                    'url' => $nav['url'],
                    'icon' => $nav['icon'],
                ]);
            }
        }

        // 2. Domains search
        $domainQuery = Domain::query()->with('account');
        if (! $user->isAdmin()) {
            if ($user->isReseller()) {
                $childIds = $user->children()->pluck('id')->push($user->id);
                $accountIds = Account::whereIn('user_id', $childIds)->pluck('id');
                $domainQuery->whereIn('account_id', $accountIds);
            } else {
                $domainQuery->whereIn('account_id', $user->accounts()->pluck('id'));
            }
        }

        $matchedDomains = $domainQuery->where('domain', 'like', "%{$trimmed}%")
            ->limit(5)
            ->get();

        foreach ($matchedDomains as $dom) {
            $items->push([
                'type' => 'Domain',
                'title' => $dom->domain,
                'subtitle' => "Account: {$dom->account->linux_username} (PHP {$dom->php_version})",
                'url' => route('domains.ssl', $dom),
                'icon' => 'globe',
            ]);
        }

        // 3. Accounts search
        $accountQuery = Account::query();
        if (! $user->isAdmin()) {
            if ($user->isReseller()) {
                $childIds = $user->children()->pluck('id')->push($user->id);
                $accountQuery->whereIn('user_id', $childIds);
            } else {
                $accountQuery->where('user_id', $user->id);
            }
        }

        $matchedAccounts = $accountQuery->where(function ($q) use ($trimmed) {
            $q->where('linux_username', 'like', "%{$trimmed}%")
                ->orWhere('primary_domain', 'like', "%{$trimmed}%");
        })->limit(5)->get();

        foreach ($matchedAccounts as $acc) {
            $items->push([
                'type' => 'Account',
                'title' => $acc->linux_username,
                'subtitle' => "Primary: {$acc->primary_domain}",
                'url' => route('accounts.show', $acc),
                'icon' => 'user',
            ]);
        }

        // 4. Servers search (Admin only)
        if ($user->isAdmin()) {
            $matchedServers = Server::query()
                ->where('name', 'like', "%{$trimmed}%")
                ->orWhere('private_ip', 'like', "%{$trimmed}%")
                ->limit(3)
                ->get();

            foreach ($matchedServers as $srv) {
                $items->push([
                    'type' => 'Server',
                    'title' => $srv->name,
                    'subtitle' => "IP: {$srv->private_ip} | Status: {$srv->status->value}",
                    'url' => route('servers.index'),
                    'icon' => 'server',
                ]);
            }
        }

        $this->results = $items->values()->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function getNavigationItems(User $user): array
    {
        $items = [
            [
                'title' => 'Dashboard',
                'subtitle' => 'System metrics and resource launchpad',
                'url' => route('dashboard'),
                'icon' => 'home',
                'keywords' => 'overview home statistics metrics launchpad',
            ],
            [
                'title' => 'Websites & Domains',
                'subtitle' => 'Manage virtual hosts, redirects, and document roots',
                'url' => route('domains.index'),
                'icon' => 'globe',
                'keywords' => 'domain site virtualhost vhost ssl redirect',
            ],
            [
                'title' => 'Security & Profile',
                'subtitle' => 'Two-factor auth and account settings',
                'url' => route('profile.security'),
                'icon' => 'shield',
                'keywords' => '2fa two-factor password credentials profile',
            ],
        ];

        if ($user->isAdmin() || $user->isReseller()) {
            $items[] = [
                'title' => 'Hosting Packages',
                'subtitle' => 'Configure disk, bandwidth, and resource plans',
                'url' => route('packages.index'),
                'icon' => 'box',
                'keywords' => 'package plan tiers limits quota',
            ];
            $items[] = [
                'title' => 'Hosting Accounts',
                'subtitle' => 'List and manage customer accounts',
                'url' => route('accounts.index'),
                'icon' => 'users',
                'keywords' => 'accounts clients users provision',
            ];
        }

        if ($user->isReseller()) {
            $items[] = [
                'title' => 'Reseller Control Center',
                'subtitle' => 'White-label branding and customer pool overview',
                'url' => route('reseller.index'),
                'icon' => 'briefcase',
                'keywords' => 'reseller whitelabel branding quota pool customers',
            ];
        }

        if ($user->isAdmin()) {
            $items[] = [
                'title' => 'Server Nodes',
                'subtitle' => 'Manage multi-server nodes, drain mode, and enrollment',
                'url' => route('servers.index'),
                'icon' => 'server',
                'keywords' => 'servers nodes drain enroll mtls cluster',
            ];
            $items[] = [
                'title' => 'Audit Logs',
                'subtitle' => 'Tamper-evident system activity log',
                'url' => route('admin.audit-logs'),
                'icon' => 'clipboard',
                'keywords' => 'logs audit security trail events',
            ];
            $items[] = [
                'title' => 'Agent Jobs',
                'subtitle' => 'Monitor background server agent execution tasks',
                'url' => route('admin.jobs.index'),
                'icon' => 'cpu',
                'keywords' => 'jobs tasks queue agent execution',
            ];
            $items[] = [
                'title' => 'Quotas & Usage Telemetry',
                'subtitle' => 'Disk, inodes, and monthly bandwidth monitoring',
                'url' => route('admin.usage.index'),
                'icon' => 'chart',
                'keywords' => 'usage bandwidth storage quotas metrics',
            ];
            $items[] = [
                'title' => 'API Tokens & Webhooks',
                'subtitle' => 'REST API access tokens and automated webhooks',
                'url' => route('admin.api-tokens.index'),
                'icon' => 'key',
                'keywords' => 'api token webhooks automation keys',
            ];
        }

        return $items;
    }

    public function render(): View
    {
        return view('livewire.navigation.global-search');
    }
}
