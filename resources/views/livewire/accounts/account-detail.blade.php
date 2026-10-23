<div>
    <x-layout.page-header 
        title="{{ $account->primary_domain }}" 
        subtitle="Linux User: {{ $account->linux_username }} &middot; Home: /home/{{ $account->linux_username }}"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.chip :variant="match($account->status) {
                    App\Enums\AccountStatus::ACTIVE => 'success',
                    App\Enums\AccountStatus::PENDING => 'warning',
                    App\Enums\AccountStatus::SUSPENDED, App\Enums\AccountStatus::FAILED => 'danger',
                    App\Enums\AccountStatus::TERMINATING => 'info',
                    default => 'default',
                }">
                    {{ $account->status->label() }}
                </x-ui.chip>

                @if ($canManage)
                    @if ($account->status === App\Enums\AccountStatus::FAILED)
                        <x-ui.button type="button" wire:click="retryProvisioning" variant="warning" size="sm">
                            Retry Provisioning
                        </x-ui.button>
                    @endif

                    @if ($account->status === App\Enums\AccountStatus::ACTIVE)
                        <x-ui.button type="button" wire:click="$set('showingUpgradeModal', true)" variant="outline" size="sm">
                            Upgrade Package
                        </x-ui.button>
                        <x-ui.button type="button" wire:click="$set('showingSuspendModal', true)" variant="danger" size="sm">
                            Suspend Account
                        </x-ui.button>
                    @elseif ($account->status === App\Enums\AccountStatus::SUSPENDED)
                        <x-ui.button type="button" wire:click="unsuspendAccount" wire:confirm="Are you sure you want to unsuspend this account?" variant="primary" size="sm">
                            Unsuspend Account
                        </x-ui.button>
                    @endif
                @endif

                @if ($canDelete && $account->status !== App\Enums\AccountStatus::TERMINATED && $account->status !== App\Enums\AccountStatus::TERMINATING)
                    <x-ui.button type="button" wire:click="$set('showingTerminateModal', true)" variant="danger" size="sm">
                        Terminate Account
                    </x-ui.button>
                @endif

                <x-ui.button href="{{ route('accounts.index') }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Accounts
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Access & Tools Quick Bar -->
    <div class="mb-6 flex flex-wrap gap-2">
        <a href="{{ route('accounts.files', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium bg-card hover:bg-shell text-main border border-border transition shadow-xs">
            📁 File Manager
        </a>
        <a href="{{ route('accounts.ftp', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium bg-card hover:bg-shell text-main border border-border transition shadow-xs">
            🔌 FTP / SFTP Accounts
        </a>
        <a href="{{ route('accounts.ssh', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium bg-card hover:bg-shell text-main border border-border transition shadow-xs">
            🔑 SSH Keys
        </a>
        <a href="{{ route('accounts.databases', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium bg-card hover:bg-shell text-main border border-border transition shadow-xs">
            🗄️ Databases
        </a>
        <a href="{{ route('accounts.cron', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium bg-card hover:bg-shell text-main border border-border transition shadow-xs">
            ⏰ Cron Jobs
        </a>
        <a href="{{ route('accounts.logs', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium bg-card hover:bg-shell text-main border border-border transition shadow-xs">
            📜 Logs &amp; Diagnostics
        </a>
        <a href="{{ route('accounts.backups', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium bg-card hover:bg-shell text-main border border-border transition shadow-xs">
            💾 Backups
        </a>
        <a href="{{ route('accounts.git', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium bg-card hover:bg-shell text-main border border-border transition shadow-xs">
            🚀 Git Deploy
        </a>
        <a href="{{ route('accounts.wordpress', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium bg-card hover:bg-shell text-main border border-border transition shadow-xs">
            🌐 WordPress
        </a>
        <a href="{{ route('accounts.protected-dirs', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium bg-card hover:bg-shell text-main border border-border transition shadow-xs">
            🔒 Protected Dirs
        </a>
        <a href="{{ route('accounts.usage', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium bg-card hover:bg-shell text-main border border-border transition shadow-xs">
            📊 Usage &amp; Quotas
        </a>
    </div>

    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    @if ($account->suspended_reason)
        <div class="mb-6 p-4 rounded-[20px] bg-rose-500/10 border border-rose-500/20 text-rose-500 dark:text-rose-400 text-sm">
            <strong>Suspension Notice:</strong> {{ $account->suspended_reason }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Account Details Card -->
        <x-ui.card>
            <h3 class="text-xs font-semibold uppercase tracking-wider text-muted mb-4">Ownership &amp; Host</h3>
            <dl class="space-y-3 text-xs">
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Account Owner:</dt>
                    <dd class="text-main font-semibold">{{ $account->user->name }}</dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Owner Email:</dt>
                    <dd class="text-muted">{{ $account->user->email }}</dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Server Node:</dt>
                    <dd class="text-primary-500 font-mono font-medium">{{ $account->server->name }}</dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Private IP:</dt>
                    <dd class="text-muted font-mono">{{ $account->server->private_ip }}</dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Created At:</dt>
                    <dd class="text-muted">{{ $account->created_at?->format('Y-m-d H:i') }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <!-- Package Limits Card -->
        <x-ui.card>
            <h3 class="text-xs font-semibold uppercase tracking-wider text-muted mb-4">
                Package: {{ $account->package->name }}
            </h3>
            <dl class="space-y-3 text-xs">
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Disk Quota:</dt>
                    <dd class="text-main font-mono">{{ round($account->package->storage_mb / 1024, 1) }} GB</dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Inode Limit:</dt>
                    <dd class="text-main font-mono">{{ number_format($account->package->inodes) }}</dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Monthly Bandwidth:</dt>
                    <dd class="text-main font-mono">{{ $account->package->bandwidth_gb }} GB</dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Websites Allowed:</dt>
                    <dd class="text-main font-mono">{{ $account->package->max_websites }}</dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Databases Allowed:</dt>
                    <dd class="text-main font-mono">{{ $account->package->max_databases }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <!-- PHP & Runtime Card -->
        <x-ui.card>
            <h3 class="text-xs font-semibold uppercase tracking-wider text-muted mb-4">PHP Runtime &amp; Access</h3>
            <dl class="space-y-3 text-xs">
                <div class="flex justify-between items-center">
                    <dt class="text-muted">PHP Workers:</dt>
                    <dd class="text-main font-mono">{{ $account->package->php_workers }}</dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Memory Limit:</dt>
                    <dd class="text-main font-mono">{{ $account->package->php_memory_mb }} MB</dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">SSH Access:</dt>
                    <dd class="{{ $account->package->ssh_enabled ? 'text-emerald-500 font-medium' : 'text-muted' }}">
                        {{ $account->package->ssh_enabled ? 'Enabled' : 'Disabled' }}
                    </dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">Git Deployment:</dt>
                    <dd class="{{ $account->package->git_enabled ? 'text-emerald-500 font-medium' : 'text-muted' }}">
                        {{ $account->package->git_enabled ? 'Enabled' : 'Disabled' }}
                    </dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-muted">PHP Versions:</dt>
                    <dd class="text-xs font-mono text-muted">{{ implode(', ', $account->package->allowed_php_versions ?? []) }}</dd>
                </div>
            </dl>
        </x-ui.card>
    </div>

    <!-- Agent Job Protocol History -->
    @if ($recentAgentJobs->isNotEmpty())
        <x-ui.card class="p-0 overflow-hidden mb-8">
            <div class="px-6 py-4 border-b border-border flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-main">Agent Protocol Executions</h3>
                    <p class="text-xs text-muted mt-0.5">Asynchronous provisioning, suspension, and upgrade actions executed on the agent node.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                            <th class="py-3 px-6">Timestamp</th>
                            <th class="py-3 px-6">Action</th>
                            <th class="py-3 px-6">Status</th>
                            <th class="py-3 px-6">Recent Log Line</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-main">
                        @foreach ($recentAgentJobs as $agentJob)
                            <tr class="hover:bg-shell/40 transition font-mono">
                                <td class="px-6 py-3 text-muted">{{ $agentJob->created_at?->format('Y-m-d H:i:s') }}</td>
                                <td class="px-6 py-3 text-primary-500 font-semibold">{{ $agentJob->action }}</td>
                                <td class="px-6 py-3">
                                    <x-ui.chip :variant="match($agentJob->status->value) {
                                        'completed' => 'success',
                                        'failed' => 'danger',
                                        'running' => 'info',
                                        default => 'warning',
                                    }" size="sm">
                                        {{ $agentJob->status->value }}
                                    </x-ui.chip>
                                </td>
                                <td class="px-6 py-3 text-muted truncate max-w-xs font-sans">
                                    {{ $agentJob->logs->last()?->message ?? 'No logs recorded' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    <!-- Audit Trail -->
    <x-ui.card class="p-0 overflow-hidden">
        <div class="px-6 py-4 border-b border-border">
            <h3 class="text-sm font-semibold text-main">Account Audit Trail</h3>
            <p class="text-xs text-muted mt-0.5">Historical log of provisioning, state mutations, and operations for this account.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="py-3 px-6">Timestamp</th>
                        <th class="py-3 px-6">Action</th>
                        <th class="py-3 px-6">Actor</th>
                        <th class="py-3 px-6">IP Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-main">
                    @forelse ($recentLogs as $log)
                        <tr class="hover:bg-shell/40 transition">
                            <td class="px-6 py-3 font-mono text-muted">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            <td class="px-6 py-3 font-mono text-primary-500 font-medium">{{ $log->action }}</td>
                            <td class="px-6 py-3 text-main">{{ $log->user?->name ?? 'System' }}</td>
                            <td class="px-6 py-3 font-mono text-muted">{{ $log->ip_address ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-6 text-center text-muted text-xs">
                                No audit log events recorded for this account yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <!-- Suspend Modal -->
    @if ($showingSuspendModal)
        <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-main">Suspend Account: {{ $account->primary_domain }}</h3>
                <p class="text-xs text-muted mt-1">This will stop PHP pools, disable login, and show a suspended notice.</p>

                <div class="mt-4">
                    <label class="block text-xs font-medium text-muted mb-1.5">Reason for Suspension</label>
                    <x-ui.textarea wire:model="suspendReason" rows="3" placeholder="e.g. Terms of service violation, billing overdue..." />
                    @error('suspendReason') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <x-ui.button type="button" wire:click="$set('showingSuspendModal', false)" variant="ghost" size="sm">
                        Cancel
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="suspendAccount" variant="danger" size="sm">
                        Confirm Suspension
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- Upgrade Modal -->
    @if ($showingUpgradeModal)
        <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-main">Upgrade Package: {{ $account->primary_domain }}</h3>
                <p class="text-xs text-muted mt-1">Select a new hosting tier to adjust quotas, workers, and resource limits.</p>

                <div class="mt-4">
                    <label class="block text-xs font-medium text-muted mb-1.5">Choose Package</label>
                    <x-ui.select wire:model="newPackageId">
                        @foreach ($availablePackages as $pkg)
                            <option value="{{ $pkg->id }}">{{ $pkg->name }} ({{ round($pkg->storage_mb / 1024, 1) }} GB, {{ $pkg->php_workers }} workers)</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <x-ui.button type="button" wire:click="$set('showingUpgradeModal', false)" variant="ghost" size="sm">
                        Cancel
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="upgradePackage" variant="primary" size="sm">
                        Apply Upgrade
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- Terminate Modal -->
    @if ($showingTerminateModal)
        <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <div class="flex items-center gap-2 text-rose-500 mb-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <h3 class="text-lg font-bold text-main">Permanently Terminate Account</h3>
                </div>
                <p class="text-xs text-muted">
                    Warning: This action will permanently remove Linux user <strong class="font-mono text-main">{{ $account->linux_username }}</strong>, delete all files, vhosts, pools, and crontabs!
                </p>

                <div class="mt-4">
                    <label class="flex items-center text-xs text-main cursor-pointer">
                        <input type="checkbox" wire:model="terminateWithBackup" class="rounded-sm bg-card border-border text-primary-500 mr-2">
                        Create final archive tarball in /var/backups before deletion
                    </label>
                </div>

                <div class="mt-4">
                    <label class="block text-xs font-medium text-muted mb-1.5">
                        Type <code class="font-mono text-rose-500 bg-shell px-2 py-0.5 rounded-full border border-border select-all">TERMINATE-{{ $account->linux_username }}</code> to confirm:
                    </label>
                    <x-ui.input type="text" wire:model="terminateConfirmation" placeholder="TERMINATE-{{ $account->linux_username }}" class="font-mono text-xs" />
                    @error('terminateConfirmation') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <x-ui.button type="button" wire:click="$set('showingTerminateModal', false)" variant="ghost" size="sm">
                        Cancel
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="terminateAccount" variant="danger" size="sm">
                        Permanently Terminate
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
