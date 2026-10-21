<div>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h2 class="text-2xl font-bold text-white tracking-tight">{{ $account->primary_domain }}</h2>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold
                    @if ($account->status === App\Enums\AccountStatus::ACTIVE) bg-emerald-500/10 text-emerald-400 border border-emerald-500/20
                    @elseif ($account->status === App\Enums\AccountStatus::PENDING) bg-amber-500/10 text-amber-400 border border-amber-500/20
                    @elseif ($account->status === App\Enums\AccountStatus::SUSPENDED) bg-rose-500/10 text-rose-400 border border-rose-500/20
                    @elseif ($account->status === App\Enums\AccountStatus::FAILED) bg-red-500/10 text-red-400 border border-red-500/20
                    @elseif ($account->status === App\Enums\AccountStatus::TERMINATING) bg-purple-500/10 text-purple-400 border border-purple-500/20
                    @elseif ($account->status === App\Enums\AccountStatus::TERMINATED) bg-slate-800 text-slate-400 border border-slate-700
                    @else bg-slate-800 text-slate-400 border border-slate-700
                    @endif">
                    {{ $account->status->label() }}
                </span>
            </div>
            <p class="text-sm text-slate-400 mt-1">
                Linux User: <span class="font-mono text-indigo-400 font-semibold">{{ $account->linux_username }}</span> &middot;
                Home: <span class="font-mono text-slate-300">/home/{{ $account->linux_username }}</span>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($canManage)
                @if ($account->status === App\Enums\AccountStatus::FAILED)
                    <button type="button" wire:click="retryProvisioning"
                        class="px-3.5 py-1.5 rounded-lg text-xs font-medium bg-amber-600/20 hover:bg-amber-600/30 text-amber-300 border border-amber-500/30 transition">
                        Retry Provisioning
                    </button>
                @endif

                @if ($account->status === App\Enums\AccountStatus::ACTIVE)
                    <button type="button" wire:click="$set('showingUpgradeModal', true)"
                        class="px-3.5 py-1.5 rounded-lg text-xs font-medium bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 border border-indigo-500/30 transition">
                        Upgrade Package
                    </button>
                    <button type="button" wire:click="$set('showingSuspendModal', true)"
                        class="px-3.5 py-1.5 rounded-lg text-xs font-medium bg-rose-600/20 hover:bg-rose-600/30 text-rose-300 border border-rose-500/30 transition">
                        Suspend Account
                    </button>
                @elseif ($account->status === App\Enums\AccountStatus::SUSPENDED)
                    <button type="button" wire:click="unsuspendAccount" wire:confirm="Are you sure you want to unsuspend this account?"
                        class="px-3.5 py-1.5 rounded-lg text-xs font-medium bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 border border-emerald-500/30 transition">
                        Unsuspend Account
                    </button>
                @endif
            @endif

            @if ($canDelete && $account->status !== App\Enums\AccountStatus::TERMINATED && $account->status !== App\Enums\AccountStatus::TERMINATING)
                <button type="button" wire:click="$set('showingTerminateModal', true)"
                    class="px-3.5 py-1.5 rounded-lg text-xs font-medium bg-rose-950/60 hover:bg-rose-900 text-rose-400 border border-rose-800 transition">
                    Terminate Account
                </button>
            @endif

            <a href="{{ route('accounts.index') }}" class="text-sm text-slate-400 hover:text-white transition ml-2">
                &larr; Back to Accounts
            </a>
        </div>
    </div>

    <!-- Access & Tools Quick Bar -->
    <div class="mb-6 flex flex-wrap gap-2">
        <a href="{{ route('accounts.files', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-xs font-semibold bg-slate-900 hover:bg-slate-800 text-white border border-slate-800 transition shadow-sm">
            📁 File Manager
        </a>
        <a href="{{ route('accounts.ftp', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-xs font-semibold bg-slate-900 hover:bg-slate-800 text-white border border-slate-800 transition shadow-sm">
            🔌 FTP / SFTP Accounts
        </a>
        <a href="{{ route('accounts.ssh', $account) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-xs font-semibold bg-slate-900 hover:bg-slate-800 text-white border border-slate-800 transition shadow-sm">
            🔑 SSH Keys
        </a>
    </div>

    @if (session()->has('status'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    @if ($account->suspended_reason)
        <div class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-sm">
            <strong>Suspension Notice:</strong> {{ $account->suspended_reason }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Account Details Card -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-400 mb-4">Ownership &amp; Host</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-400">Account Owner:</dt>
                    <dd class="text-white font-medium">{{ $account->user->name }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Owner Email:</dt>
                    <dd class="text-slate-300">{{ $account->user->email }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Server Node:</dt>
                    <dd class="text-indigo-400 font-mono">{{ $account->server->name }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Private IP:</dt>
                    <dd class="text-slate-300 font-mono">{{ $account->server->private_ip }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Created At:</dt>
                    <dd class="text-slate-400 text-xs">{{ $account->created_at?->format('Y-m-d H:i') }}</dd>
                </div>
            </dl>
        </div>

        <!-- Package Limits Card -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-400 mb-4">
                Package: {{ $account->package->name }}
            </h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-400">Disk Quota:</dt>
                    <dd class="text-white font-mono">{{ round($account->package->storage_mb / 1024, 1) }} GB</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Inode Limit:</dt>
                    <dd class="text-white font-mono">{{ number_format($account->package->inodes) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Monthly Bandwidth:</dt>
                    <dd class="text-white font-mono">{{ $account->package->bandwidth_gb }} GB</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Websites Allowed:</dt>
                    <dd class="text-white font-mono">{{ $account->package->max_websites }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Databases Allowed:</dt>
                    <dd class="text-white font-mono">{{ $account->package->max_databases }}</dd>
                </div>
            </dl>
        </div>

        <!-- PHP & Runtime Card -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-400 mb-4">PHP Runtime &amp; Access</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-400">PHP Workers:</dt>
                    <dd class="text-white font-mono">{{ $account->package->php_workers }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Memory Limit:</dt>
                    <dd class="text-white font-mono">{{ $account->package->php_memory_mb }} MB</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">SSH Access:</dt>
                    <dd class="{{ $account->package->ssh_enabled ? 'text-emerald-400' : 'text-slate-500' }}">
                        {{ $account->package->ssh_enabled ? 'Enabled' : 'Disabled' }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Git Deployment:</dt>
                    <dd class="{{ $account->package->git_enabled ? 'text-emerald-400' : 'text-slate-500' }}">
                        {{ $account->package->git_enabled ? 'Enabled' : 'Disabled' }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">PHP Versions:</dt>
                    <dd class="text-xs font-mono text-slate-300">{{ implode(', ', $account->package->allowed_php_versions ?? []) }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Agent Job Protocol History -->
    @if ($recentAgentJobs->isNotEmpty())
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-white">Agent Protocol Executions</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Asynchronous provisioning, suspension, and upgrade actions executed on the agent node.</p>
                </div>
            </div>
            <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
                <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                    <tr>
                        <th scope="col" class="px-6 py-3">Timestamp</th>
                        <th scope="col" class="px-6 py-3">Action</th>
                        <th scope="col" class="px-6 py-3">Status</th>
                        <th scope="col" class="px-6 py-3">Recent Log Line</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800 text-slate-300">
                    @foreach ($recentAgentJobs as $agentJob)
                        <tr class="hover:bg-slate-800/30 transition text-xs font-mono">
                            <td class="px-6 py-3 text-slate-400">{{ $agentJob->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td class="px-6 py-3 text-indigo-400 font-semibold">{{ $agentJob->action }}</td>
                            <td class="px-6 py-3">
                                <span class="px-2 py-0.5 rounded text-[10px] uppercase font-bold {{ match($agentJob->status->value) {
                                    'completed' => 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20',
                                    'failed' => 'bg-rose-500/10 text-rose-400 border border-rose-500/20',
                                    'running' => 'bg-blue-500/10 text-blue-400 border border-blue-500/20',
                                    default => 'bg-amber-500/10 text-amber-400 border border-amber-500/20',
                                } }}">
                                    {{ $agentJob->status->value }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-slate-400 truncate max-w-xs font-sans">
                                {{ $agentJob->logs->last()?->message ?? 'No logs recorded' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <!-- Audit Trail -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-slate-800">
            <h3 class="text-base font-semibold text-white">Account Audit Trail</h3>
            <p class="text-xs text-slate-400 mt-0.5">Historical log of provisioning, state mutations, and operations for this account.</p>
        </div>
        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                <tr>
                    <th scope="col" class="px-6 py-3">Timestamp</th>
                    <th scope="col" class="px-6 py-3">Action</th>
                    <th scope="col" class="px-6 py-3">Actor</th>
                    <th scope="col" class="px-6 py-3">IP Address</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800 text-slate-300">
                @forelse ($recentLogs as $log)
                    <tr class="hover:bg-slate-800/30 transition text-xs">
                        <td class="px-6 py-3 font-mono text-slate-400">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                        <td class="px-6 py-3 font-mono text-indigo-400">{{ $log->action }}</td>
                        <td class="px-6 py-3">{{ $log->user?->name ?? 'System' }}</td>
                        <td class="px-6 py-3 font-mono text-slate-400">{{ $log->ip_address ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-6 text-center text-slate-500 text-xs">
                            No audit log events recorded for this account yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Suspend Modal -->
    @if ($showingSuspendModal)
        <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-white">Suspend Account: {{ $account->primary_domain }}</h3>
                <p class="text-xs text-slate-400 mt-1">This will stop PHP pools, disable login, and show a suspended notice.</p>

                <div class="mt-4">
                    <label class="block text-xs font-medium text-slate-300 mb-1">Reason for Suspension</label>
                    <textarea wire:model="suspendReason" rows="3" placeholder="e.g. Terms of service violation, billing overdue..."
                        class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm p-3 border placeholder-slate-500 focus:ring-1 focus:ring-rose-500"></textarea>
                    @error('suspendReason') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button type="button" wire:click="$set('showingSuspendModal', false)"
                        class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-xs font-medium rounded-lg border border-slate-700">
                        Cancel
                    </button>
                    <button type="button" wire:click="suspendAccount"
                        class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-medium rounded-lg shadow">
                        Confirm Suspension
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Upgrade Modal -->
    @if ($showingUpgradeModal)
        <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-white">Upgrade Package: {{ $account->primary_domain }}</h3>
                <p class="text-xs text-slate-400 mt-1">Select a new hosting tier to adjust quotas, workers, and resource limits.</p>

                <div class="mt-4">
                    <label class="block text-xs font-medium text-slate-300 mb-1">Choose Package</label>
                    <select wire:model="newPackageId" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm p-2.5 border focus:ring-1 focus:ring-indigo-500">
                        @foreach ($availablePackages as $pkg)
                            <option value="{{ $pkg->id }}">{{ $pkg->name }} ({{ round($pkg->storage_mb / 1024, 1) }} GB, {{ $pkg->php_workers }} workers)</option>
                        @endforeach
                    </select>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button type="button" wire:click="$set('showingUpgradeModal', false)"
                        class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-xs font-medium rounded-lg border border-slate-700">
                        Cancel
                    </button>
                    <button type="button" wire:click="upgradePackage"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium rounded-lg shadow">
                        Apply Upgrade
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Terminate Modal -->
    @if ($showingTerminateModal)
        <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl">
                <div class="flex items-center gap-2 text-rose-400 mb-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <h3 class="text-lg font-bold text-white">Permanently Terminate Account</h3>
                </div>
                <p class="text-xs text-rose-300/80">
                    Warning: This action will permanently remove Linux user <strong class="font-mono">{{ $account->linux_username }}</strong>, delete all files, vhosts, pools, and crontabs!
                </p>

                <div class="mt-4">
                    <label class="flex items-center text-xs text-slate-300 cursor-pointer">
                        <input type="checkbox" wire:model="terminateWithBackup" class="rounded bg-slate-800 border-slate-700 text-indigo-600 mr-2">
                        Create final archive tarball in /var/backups before deletion
                    </label>
                </div>

                <div class="mt-4">
                    <label class="block text-xs font-medium text-slate-300 mb-1">
                        Type <code class="font-mono text-rose-400 bg-slate-800 px-1 py-0.5 rounded select-all">TERMINATE-{{ $account->linux_username }}</code> to confirm:
                    </label>
                    <input type="text" wire:model="terminateConfirmation" placeholder="TERMINATE-{{ $account->linux_username }}"
                        class="w-full rounded-lg bg-slate-800 border-slate-700 text-white font-mono text-sm p-2.5 border focus:ring-1 focus:ring-rose-500">
                    @error('terminateConfirmation') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button type="button" wire:click="$set('showingTerminateModal', false)"
                        class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-xs font-medium rounded-lg border border-slate-700">
                        Cancel
                    </button>
                    <button type="button" wire:click="terminateAccount"
                        class="px-4 py-2 bg-rose-700 hover:bg-rose-600 text-white text-xs font-medium rounded-lg shadow">
                        Permanently Terminate
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
