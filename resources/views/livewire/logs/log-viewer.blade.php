<div @if($autoRefresh) wire:poll.5s="fetchLogLines" @endif>
    <!-- Header -->
    <x-layout.page-header 
        title="Logs &amp; Diagnostics &middot; {{ $account->primary_domain }}" 
        subtitle="Live streaming and inspection of Nginx web access, error logs, PHP-FPM errors, and cron task executions."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('accounts.show', $account) }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Account
                </x-ui.button>
                <x-ui.button wire:click="triggerRotate" wire:confirm="Trigger log rotation now?" variant="outline" size="sm">
                    <span>🔄</span> Rotate Logs
                </x-ui.button>
                @if ($selectedLog)
                    <x-ui.button href="{{ route('accounts.logs.download', ['account' => $account, 'logFile' => $selectedLog]) }}" variant="primary" size="sm">
                        <span>⬇️</span> Download Log
                    </x-ui.button>
                @endif
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Status Alerts -->
    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="mb-6 rounded-[20px] bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-500 dark:text-rose-400">
            {{ session('error') }}
        </div>
    @endif
    @if ($statusMessage)
        <div class="mb-6 rounded-[20px] bg-amber-500/10 border border-amber-500/20 p-4 text-sm text-amber-600 dark:text-amber-300">
            {{ $statusMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Sidebar: Log Selection & Categories -->
        <div class="lg:col-span-1 space-y-4">
            <!-- Filter Tabs -->
            <div class="bg-card border border-border rounded-full p-1 flex gap-1 text-xs">
                <button type="button" wire:click="setTab('all')"
                    class="flex-1 py-1.5 rounded-full text-center font-medium transition cursor-pointer {{ $tab === 'all' ? 'bg-primary-500 text-white shadow-xs' : 'text-muted hover:text-main' }}">
                    All
                </button>
                <button type="button" wire:click="setTab('web')"
                    class="flex-1 py-1.5 rounded-full text-center font-medium transition cursor-pointer {{ $tab === 'web' ? 'bg-primary-500 text-white shadow-xs' : 'text-muted hover:text-main' }}">
                    Web
                </button>
                <button type="button" wire:click="setTab('php')"
                    class="flex-1 py-1.5 rounded-full text-center font-medium transition cursor-pointer {{ $tab === 'php' ? 'bg-primary-500 text-white shadow-xs' : 'text-muted hover:text-main' }}">
                    PHP
                </button>
                <button type="button" wire:click="setTab('cron')"
                    class="flex-1 py-1.5 rounded-full text-center font-medium transition cursor-pointer {{ $tab === 'cron' ? 'bg-primary-500 text-white shadow-xs' : 'text-muted hover:text-main' }}">
                    Cron
                </button>
            </div>

            <!-- Available Logs List -->
            <x-ui.card class="p-0 overflow-hidden">
                <div class="px-4 py-3 border-b border-border flex justify-between items-center">
                    <span class="text-xs uppercase tracking-wider font-semibold text-muted">Available Logs</span>
                    <button type="button" wire:click="refreshLogList" class="text-xs text-primary-500 hover:text-primary-600 cursor-pointer">↻</button>
                </div>
                <div class="divide-y divide-border max-h-[500px] overflow-y-auto">
                    @forelse ($filteredLogs as $logItem)
                        <button type="button" wire:click="selectLog('{{ $logItem['name'] }}')"
                            class="w-full text-left px-4 py-3 text-xs transition flex flex-col gap-1 cursor-pointer {{ $selectedLog === $logItem['name'] ? 'bg-primary-500/10 border-l-2 border-primary-500 text-main font-semibold' : 'hover:bg-shell/40 text-muted' }}">
                            <div class="font-mono font-semibold truncate {{ $selectedLog === $logItem['name'] ? 'text-primary-500' : 'text-main' }}">{{ $logItem['name'] }}</div>
                            <div class="flex items-center justify-between text-[11px] text-muted">
                                <span class="capitalize">{{ $logItem['category'] }}</span>
                                <span>{{ $logItem['size_bytes'] > 0 ? round($logItem['size_bytes'] / 1024, 1) . ' KB' : 'Empty' }}</span>
                            </div>
                        </button>
                    @empty
                        <div class="p-6 text-center text-xs text-muted">
                            No log files found in this category.
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>

        <!-- Terminal Viewer (3/4) -->
        <div class="lg:col-span-3 space-y-4">
            <!-- Controls Bar -->
            <x-ui.card class="p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3 flex-1 min-w-[240px]">
                    <div class="relative flex-1">
                        <x-ui.input type="text" wire:model.live.debounce.300ms="search" placeholder="Search / filter logs (e.g. 500, error, GET)..." class="font-mono text-xs pl-8" />
                        <span class="absolute left-3 top-2.5 text-muted text-xs">🔍</span>
                    </div>

                    <x-ui.select wire:model.live="lines" class="font-mono text-xs w-32">
                        <option value="50">50 lines</option>
                        <option value="100">100 lines</option>
                        <option value="250">250 lines</option>
                        <option value="500">500 lines</option>
                        <option value="1000">1000 lines</option>
                    </x-ui.select>
                </div>

                <div class="flex items-center gap-3 text-xs">
                    <label class="inline-flex items-center gap-1.5 text-muted hover:text-main cursor-pointer select-none">
                        <input type="checkbox" wire:model.live="autoRefresh" class="rounded-sm border-border bg-card text-primary-500 focus:ring-primary-500">
                        <span>Auto-tail (5s)</span>
                    </label>

                    <x-ui.button type="button" wire:click="fetchLogLines" variant="outline" size="sm">
                        ↻ Refresh
                    </x-ui.button>
                </div>
            </x-ui.card>

            <!-- Terminal Window -->
            <div class="bg-card border border-border rounded-[20px] overflow-hidden shadow-xs">
                <div class="px-4 py-2.5 bg-shell/80 border-b border-border flex justify-between items-center text-xs">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full bg-rose-500/80 inline-block"></span>
                        <span class="w-3 h-3 rounded-full bg-amber-500/80 inline-block"></span>
                        <span class="w-3 h-3 rounded-full bg-emerald-500/80 inline-block"></span>
                        <span class="font-mono text-main ml-2 font-semibold">{{ $selectedLog ?: 'No log selected' }}</span>
                    </div>
                    <div class="font-mono text-[11px] text-muted">
                        {{ count($logContent) }} lines &middot; {{ $fileSizeBytes > 0 ? round($fileSizeBytes / 1024, 1) . ' KB' : '0 B' }}
                    </div>
                </div>

                <div class="p-4 font-mono text-[11px] leading-relaxed max-h-[550px] overflow-y-auto overflow-x-auto select-text bg-[var(--bg-shell)] text-[var(--text)]">
                    @forelse ($logContent as $idx => $line)
                        <div class="flex gap-3 hover:bg-white/5 py-0.5 px-1 rounded transition-colors">
                            <span class="text-slate-600 select-none text-right w-8 inline-block shrink-0">{{ $idx + 1 }}</span>
                            <span class="whitespace-pre-wrap break-all flex-1 {{
                                preg_match('/(error|fatal|exception|fail|500|502|503|504)/i', $line) ? 'text-rose-400 font-medium' : (
                                preg_match('/(warning|notice|404|403)/i', $line) ? 'text-amber-300' : (
                                preg_match('/(200|201|204|success)/i', $line) ? 'text-emerald-400' : 'text-slate-300'
                            )) }}">
                                {{ $line }}
                            </span>
                        </div>
                    @empty
                        <div class="py-16 text-center text-slate-500 italic">
                            @if ($search)
                                No entries matching filter query "{{ $search }}".
                            @else
                                No entries recorded in this log file yet.
                            @endif
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
