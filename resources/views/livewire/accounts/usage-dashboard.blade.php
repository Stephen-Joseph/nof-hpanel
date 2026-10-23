<div>
    <!-- Header -->
    <x-layout.page-header 
        title="Resource Usage &middot; {{ $account->primary_domain }}" 
        subtitle="Real-time disk storage, file inodes, monthly bandwidth, and database quota tracking."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('accounts.show', $account) }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Account
                </x-ui.button>
                <x-ui.button wire:click="pollNow" variant="primary" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    Poll Metrics Now
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Feedback Alerts -->
    @if (session()->has('success'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('success') }}
        </div>
    @endif

    <!-- Warning / Throttling Banner -->
    @php
        $highestPct = $account->highestUsagePercentage();
        $storagePct = $account->storagePercentage();
        $inodesPct = $account->inodesPercentage();
        $bwPct = $account->bandwidthPercentage();
        $bwGbUsed = round($account->current_bandwidth_bytes / (1024 * 1024 * 1024), 2);
    @endphp

    @if($account->is_throttled || $highestPct >= 100)
        <div class="mb-6 rounded-[20px] bg-rose-500/10 border border-rose-500/30 p-4 sm:p-5 flex items-start gap-3">
            <div class="p-2 rounded-full bg-rose-500/20 text-rose-500 shrink-0">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <div>
                <h4 class="text-sm font-bold text-rose-600 dark:text-rose-300">Resource Quota Exceeded (100%+)</h4>
                <p class="text-xs text-rose-600/80 dark:text-rose-300/80 mt-1">This account has exceeded its allocated package allowances. Account traffic may be throttled or uploads restricted until upgraded or usage is reduced.</p>
            </div>
        </div>
    @elseif($highestPct >= 80)
        <div class="mb-6 rounded-[20px] bg-amber-500/10 border border-amber-500/30 p-4 sm:p-5 flex items-start gap-3">
            <div class="p-2 rounded-full bg-amber-500/20 text-amber-500 shrink-0">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <div>
                <h4 class="text-sm font-bold text-amber-600 dark:text-amber-300">High Resource Consumption Warning ({{ $highestPct }}%)</h4>
                <p class="text-xs text-amber-600/80 dark:text-amber-300/80 mt-1">Resource consumption has reached {{ $highestPct }}% of limits. Please consider upgrading your hosting package before limits are reached.</p>
            </div>
        </div>
    @endif

    <!-- Primary Metric Progress Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Storage Card -->
        <x-ui.card>
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">Disk Storage</span>
                <span class="text-xs font-mono font-bold {{ $storagePct >= 100 ? 'text-rose-500' : ($storagePct >= 80 ? 'text-amber-500' : 'text-emerald-500') }}">
                    {{ $storagePct }}%
                </span>
            </div>
            <div class="flex items-baseline gap-2 mb-3">
                <span class="text-2xl font-bold text-main font-mono">{{ round($account->current_storage_mb, 1) }}</span>
                <span class="text-xs text-muted font-mono">/ {{ $account->package->storage_mb }} MB</span>
            </div>
            <div class="w-full bg-shell rounded-full h-2.5 overflow-hidden border border-border">
                <div class="h-2.5 rounded-full transition-all duration-500 {{ $storagePct >= 100 ? 'bg-rose-500' : ($storagePct >= 80 ? 'bg-amber-500' : 'bg-primary-500') }}"
                    style="width: {{ min(100, $storagePct) }}%"></div>
            </div>
            <p class="text-[11px] text-muted mt-3">Calculated from account directory and database footprints.</p>
        </x-ui.card>

        <!-- Inodes Card -->
        <x-ui.card>
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">File Inodes</span>
                <span class="text-xs font-mono font-bold {{ $inodesPct >= 100 ? 'text-rose-500' : ($inodesPct >= 80 ? 'text-amber-500' : 'text-emerald-500') }}">
                    {{ $inodesPct }}%
                </span>
            </div>
            <div class="flex items-baseline gap-2 mb-3">
                <span class="text-2xl font-bold text-main font-mono">{{ number_format($account->current_inodes) }}</span>
                <span class="text-xs text-muted font-mono">/ {{ number_format($account->package->inodes) }}</span>
            </div>
            <div class="w-full bg-shell rounded-full h-2.5 overflow-hidden border border-border">
                <div class="h-2.5 rounded-full transition-all duration-500 {{ $inodesPct >= 100 ? 'bg-rose-500' : ($inodesPct >= 80 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                    style="width: {{ min(100, $inodesPct) }}%"></div>
            </div>
            <p class="text-[11px] text-muted mt-3">Total count of files, folders, and symlinks.</p>
        </x-ui.card>

        <!-- Bandwidth Card -->
        <x-ui.card>
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">Monthly Bandwidth</span>
                <span class="text-xs font-mono font-bold {{ $bwPct >= 100 ? 'text-rose-500' : ($bwPct >= 80 ? 'text-amber-500' : 'text-emerald-500') }}">
                    {{ $bwPct }}%
                </span>
            </div>
            <div class="flex items-baseline gap-2 mb-3">
                <span class="text-2xl font-bold text-main font-mono">{{ $bwGbUsed }}</span>
                <span class="text-xs text-muted font-mono">/ {{ $account->package->bandwidth_gb }} GB</span>
            </div>
            <div class="w-full bg-shell rounded-full h-2.5 overflow-hidden border border-border">
                <div class="h-2.5 rounded-full transition-all duration-500 {{ $bwPct >= 100 ? 'bg-rose-500' : ($bwPct >= 80 ? 'bg-amber-500' : 'bg-primary-500') }}"
                    style="width: {{ min(100, $bwPct) }}%"></div>
            </div>
            <p class="text-[11px] text-muted mt-3">Resets on the 1st of every month automatically.</p>
        </x-ui.card>
    </div>

    <!-- Secondary Metrics & FPM -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <x-ui.card class="p-5">
            <span class="text-xs text-muted">Websites &amp; Domains</span>
            <div class="text-lg font-bold text-main mt-1">{{ $account->domains->count() }} / {{ $account->package->max_websites }}</div>
        </x-ui.card>
        <x-ui.card class="p-5">
            <span class="text-xs text-muted">MariaDB Databases</span>
            <div class="text-lg font-bold text-main mt-1">{{ $account->databases->count() }} / {{ $account->package->max_databases }}</div>
        </x-ui.card>
        <x-ui.card class="p-5">
            <span class="text-xs text-muted">PHP-FPM Worker Pool Limit</span>
            <div class="text-lg font-bold text-main mt-1">{{ $account->package->php_workers }} workers ({{ $account->package->php_memory_mb }} MB)</div>
        </x-ui.card>
    </div>

    <!-- Recent Usage Snapshots -->
    <x-ui.card class="p-0 overflow-hidden">
        <div class="px-6 py-4 border-b border-border flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-main">15-Minute Metric Telemetry</h3>
                <p class="text-xs text-muted mt-0.5">Historical snapshots collected by the server agent.</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="py-3 px-6">Timestamp</th>
                        <th class="py-3 px-6">Storage (MB)</th>
                        <th class="py-3 px-6">Inodes</th>
                        <th class="py-3 px-6">Interval Bandwidth</th>
                        <th class="py-3 px-6">Database Footprint</th>
                        <th class="py-3 px-6">FPM Workers</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border font-mono text-main">
                    @forelse ($recentSnapshots as $snap)
                        <tr class="hover:bg-shell/40 transition">
                            <td class="px-6 py-3 font-sans text-muted">{{ $snap->recorded_at->format('Y-m-d H:i:s') }}</td>
                            <td class="px-6 py-3 font-semibold text-main">{{ $snap->storage_mb }} MB</td>
                            <td class="px-6 py-3 text-muted">{{ number_format($snap->inodes) }}</td>
                            <td class="px-6 py-3 text-primary-500">{{ $snap->bandwidth_bytes > 0 ? round($snap->bandwidth_bytes / (1024 * 1024), 2) . ' MB' : '0 MB' }}</td>
                            <td class="px-6 py-3 text-muted">{{ round($snap->database_bytes / (1024 * 1024), 2) }} MB</td>
                            <td class="px-6 py-3 text-muted">{{ $snap->fpm_active_workers }} active / {{ $snap->fpm_total_workers }} total</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-muted font-sans text-xs">No usage snapshots collected yet. Click "Poll Metrics Now" or wait for the next 15-minute interval.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
