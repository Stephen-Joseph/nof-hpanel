<div>
    <!-- Header -->
    <x-layout.page-header 
        title="System Resource Metering &amp; Quotas" 
        subtitle="Cross-account usage telemetry, storage and bandwidth monitoring, and quota enforcement."
    >
        <x-slot:actions>
            <x-ui.button wire:click="collectAll" variant="primary" size="sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Collect All Usage
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Status Alerts -->
    @if (session()->has('success'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('success') }}
        </div>
    @endif

    <!-- Filter Pills -->
    <div class="flex flex-wrap items-center gap-2 mb-6">
        <button wire:click="$set('filter', 'all')" class="px-3.5 py-1.5 rounded-full text-xs font-medium transition cursor-pointer {{ $filter === 'all' ? 'bg-primary-500 text-white shadow-xs' : 'bg-card border border-border text-muted hover:text-main' }}">
            All Accounts
        </button>
        <button wire:click="$set('filter', 'warning')" class="px-3.5 py-1.5 rounded-full text-xs font-medium transition cursor-pointer {{ $filter === 'warning' ? 'bg-amber-500 text-white shadow-xs' : 'bg-card border border-border text-muted hover:text-main' }}">
            Warning (80%+)
        </button>
        <button wire:click="$set('filter', 'critical')" class="px-3.5 py-1.5 rounded-full text-xs font-medium transition cursor-pointer {{ $filter === 'critical' ? 'bg-orange-500 text-white shadow-xs' : 'bg-card border border-border text-muted hover:text-main' }}">
            Critical (90%+)
        </button>
        <button wire:click="$set('filter', 'exceeded')" class="px-3.5 py-1.5 rounded-full text-xs font-medium transition cursor-pointer {{ $filter === 'exceeded' ? 'bg-rose-500 text-white shadow-xs' : 'bg-card border border-border text-muted hover:text-main' }}">
            Exceeded / Throttled (100%+)
        </button>
    </div>

    <!-- Accounts Quota Table -->
    <x-ui.card class="p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="py-3.5 px-6">Account &amp; Domain</th>
                        <th class="py-3.5 px-6">Package</th>
                        <th class="py-3.5 px-6">Storage Quota</th>
                        <th class="py-3.5 px-6">File Inodes</th>
                        <th class="py-3.5 px-6">Monthly Bandwidth</th>
                        <th class="py-3.5 px-6">Status</th>
                        <th class="py-3.5 px-6 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border font-mono text-main">
                    @forelse ($accounts as $acc)
                        @php
                            $sPct = $acc->storagePercentage();
                            $iPct = $acc->inodesPercentage();
                            $bPct = $acc->bandwidthPercentage();
                        @endphp
                        <tr class="hover:bg-shell/40 transition">
                            <td class="px-6 py-4">
                                <a href="{{ route('accounts.show', $acc) }}" class="font-sans font-bold text-main hover:text-primary-500 transition block">{{ $acc->primary_domain }}</a>
                                <span class="text-muted font-mono text-[11px]">{{ $acc->linux_username }}</span>
                            </td>
                            <td class="px-6 py-4 font-sans text-muted">{{ $acc->package->name }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-between text-[11px] mb-1">
                                    <span class="text-main font-semibold">{{ round($acc->current_storage_mb, 1) }} MB</span>
                                    <span class="font-bold {{ $sPct >= 100 ? 'text-rose-500' : ($sPct >= 80 ? 'text-amber-500' : 'text-muted') }}">{{ $sPct }}%</span>
                                </div>
                                <div class="w-32 bg-shell rounded-full h-1.5 overflow-hidden border border-border">
                                    <div class="h-1.5 rounded-full {{ $sPct >= 100 ? 'bg-rose-500' : ($sPct >= 80 ? 'bg-amber-500' : 'bg-primary-500') }}" style="width: {{ min(100, $sPct) }}%"></div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-between text-[11px] mb-1">
                                    <span class="text-main font-semibold">{{ number_format($acc->current_inodes) }}</span>
                                    <span class="font-bold {{ $iPct >= 100 ? 'text-rose-500' : ($iPct >= 80 ? 'text-amber-500' : 'text-muted') }}">{{ $iPct }}%</span>
                                </div>
                                <div class="w-28 bg-shell rounded-full h-1.5 overflow-hidden border border-border">
                                    <div class="h-1.5 rounded-full {{ $iPct >= 100 ? 'bg-rose-500' : ($iPct >= 80 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ min(100, $iPct) }}%"></div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-between text-[11px] mb-1">
                                    <span class="text-main font-semibold">{{ round($acc->current_bandwidth_bytes / (1024*1024*1024), 2) }} GB</span>
                                    <span class="font-bold {{ $bPct >= 100 ? 'text-rose-500' : ($bPct >= 80 ? 'text-amber-500' : 'text-muted') }}">{{ $bPct }}%</span>
                                </div>
                                <div class="w-28 bg-shell rounded-full h-1.5 overflow-hidden border border-border">
                                    <div class="h-1.5 rounded-full {{ $bPct >= 100 ? 'bg-rose-500' : ($bPct >= 80 ? 'bg-amber-500' : 'bg-primary-500') }}" style="width: {{ min(100, $bPct) }}%"></div>
                                </div>
                            </td>
                            <td class="px-6 py-4 font-sans">
                                @if($acc->is_throttled)
                                    <x-ui.chip variant="danger" size="sm">Throttled</x-ui.chip>
                                @elseif($acc->highestUsagePercentage() >= 100)
                                    <x-ui.chip variant="danger" size="sm">Quota Exceeded</x-ui.chip>
                                @elseif($acc->highestUsagePercentage() >= 80)
                                    <x-ui.chip variant="warning" size="sm">Warning</x-ui.chip>
                                @else
                                    <x-ui.chip variant="success" size="sm">Normal</x-ui.chip>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right font-sans space-x-2">
                                <a href="{{ route('accounts.usage', $acc) }}" class="text-primary-500 hover:text-primary-600 text-xs font-medium">Metrics</a>
                                <button wire:click="toggleThrottle({{ $acc->id }})" class="text-muted hover:text-main text-xs font-medium cursor-pointer">
                                    {{ $acc->is_throttled ? 'Unthrottle' : 'Throttle' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-muted font-sans text-xs">No accounts match the selected filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
