<div @if($autoRefresh) wire:poll.3s @endif>
    <!-- Header -->
    <x-layout.page-header 
        title="Agent Job Protocol Monitor" 
        subtitle="Real-time status of signed agent actions, server executions, and log streams."
    >
        <x-slot:actions>
            <div class="flex items-center gap-3">
                <label class="flex items-center text-xs text-muted cursor-pointer bg-card border border-border px-3.5 py-1.5 rounded-full shadow-xs">
                    <input type="checkbox" wire:model.live="autoRefresh" class="rounded-sm bg-card border-border text-primary-500 mr-2 focus:ring-0">
                    Auto-refresh (3s)
                </label>

                @if($servers->isNotEmpty())
                    <div x-data="{ open: false }" class="relative inline-block text-left">
                        <x-ui.button @click="open = !open" type="button" variant="outline" size="sm">
                            <svg class="w-4 h-4 mr-1.5 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            Test Server Ping
                        </x-ui.button>
                        <div x-show="open" @click.away="open = false" class="origin-top-right absolute right-0 mt-2 w-56 rounded-[16px] shadow-lg bg-card border border-border z-20 py-1.5">
                            @foreach($servers as $srv)
                                <button wire:click="dispatchPing({{ $srv->id }})" @click="open = false" class="block w-full text-left px-4 py-2 text-xs text-main hover:bg-shell transition cursor-pointer">
                                    {{ $srv->name }} ({{ $srv->hostname }})
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400 flex items-center justify-between">
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <!-- Filters -->
    <x-ui.card class="mb-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs font-medium text-muted mb-1.5">Search Action / Job ID / Server</label>
            <x-ui.input type="text" wire:model.live.debounce.300ms="search" placeholder="Filter action, UUID or server..." />
        </div>

        <div>
            <label class="block text-xs font-medium text-muted mb-1.5">Status</label>
            <x-ui.select wire:model.live="status">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="running">Running</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
            </x-ui.select>
        </div>

        <div>
            <label class="block text-xs font-medium text-muted mb-1.5">Server</label>
            <x-ui.select wire:model.live="serverId">
                <option value="">All Servers</option>
                @foreach ($servers as $srv)
                    <option value="{{ $srv->id }}">{{ $srv->name }} ({{ $srv->hostname }})</option>
                @endforeach
            </x-ui.select>
        </div>
    </x-ui.card>

    <!-- Jobs Table -->
    <x-ui.card class="p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="py-3 px-6">Job ID / Time</th>
                        <th class="py-3 px-6">Server</th>
                        <th class="py-3 px-6">Action</th>
                        <th class="py-3 px-6">Status</th>
                        <th class="py-3 px-6">Attempts</th>
                        <th class="py-3 px-6 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-main">
                    @forelse ($jobs as $job)
                        <tr class="hover:bg-shell/40 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-mono text-xs text-primary-500 font-medium block truncate max-w-xs">{{ $job->id }}</span>
                                <span class="text-xs text-muted">{{ $job->created_at?->diffForHumans() }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="font-medium text-main">{{ $job->server->name }}</div>
                                <div class="text-xs text-muted font-mono">{{ $job->server->hostname }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full font-mono text-xs font-semibold bg-shell text-primary-500 border border-border">
                                    {{ $job->action }}
                                </span>
                                @if($job->dry_run)
                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-amber-500/10 text-amber-500 border border-amber-500/20">
                                        dry-run
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <x-ui.chip :variant="match ($job->status->value) {
                                    'pending' => 'warning',
                                    'running' => 'info',
                                    'completed' => 'success',
                                    'failed' => 'danger',
                                    default => 'default',
                                }" size="sm" :class="$job->status->value === 'running' ? 'animate-pulse' : ''">
                                    {{ $job->status->label() }}
                                </x-ui.chip>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs text-muted font-mono">
                                {{ $job->attempts }} / {{ $job->max_attempts }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-xs space-x-2">
                                <x-ui.button wire:click="viewJob('{{ $job->id }}')" variant="outline" size="sm">
                                    View Logs
                                </x-ui.button>
                                @if($job->status->value === 'failed')
                                    <x-ui.button wire:click="retryJob('{{ $job->id }}')" variant="danger" size="sm">
                                        Retry
                                    </x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-muted text-xs">
                                No agent jobs found matching the current criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($jobs->hasPages())
            <div class="px-6 py-4 border-t border-border">
                {{ $jobs->links() }}
            </div>
        @endif
    </x-ui.card>

    <!-- Job Details / Logs Modal -->
    @if ($selectedJob)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-4xl w-full p-6 shadow-2xl relative max-h-[90vh] flex flex-col">
                <!-- Modal Header -->
                <div class="flex items-start justify-between border-b border-border pb-4 mb-4">
                    <div>
                        <div class="flex items-center space-x-2">
                            <h3 class="text-lg font-bold text-main">{{ $selectedJob->action }}</h3>
                            <x-ui.chip :variant="match ($selectedJob->status->value) {
                                'pending' => 'warning',
                                'running' => 'info',
                                'completed' => 'success',
                                'failed' => 'danger',
                                default => 'default',
                            }" size="sm">
                                {{ $selectedJob->status->label() }}
                            </x-ui.chip>
                            @if($selectedJob->dry_run)
                                <span class="px-2 py-0.5 rounded-full text-[10px] bg-amber-500/10 text-amber-500 border border-amber-500/20">dry-run</span>
                            @endif
                        </div>
                        <p class="text-xs text-muted font-mono mt-1">ID: {{ $selectedJob->id }} &bull; Server: {{ $selectedJob->server->name }} ({{ $selectedJob->server->hostname }})</p>
                    </div>
                    <button wire:click="closeModal" class="text-muted hover:text-main text-xl leading-none cursor-pointer">&times;</button>
                </div>

                <!-- Modal Body -->
                <div class="overflow-y-auto space-y-4 flex-1 pr-2">
                    <!-- Error Alert -->
                    @if ($selectedJob->error_message)
                        <div class="rounded-[16px] bg-rose-500/10 border border-rose-500/20 p-3.5 text-xs text-rose-500 font-mono">
                            <span class="font-bold">Error:</span> {{ $selectedJob->error_message }}
                        </div>
                    @endif

                    <!-- Execution Timing -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs bg-shell p-3.5 rounded-[16px] border border-border">
                        <div>
                            <span class="text-muted block">Created</span>
                            <span class="text-main font-mono">{{ $selectedJob->created_at?->format('H:i:s') }}</span>
                        </div>
                        <div>
                            <span class="text-muted block">Started</span>
                            <span class="text-main font-mono">{{ $selectedJob->started_at?->format('H:i:s') ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-muted block">Completed</span>
                            <span class="text-main font-mono">{{ $selectedJob->completed_at?->format('H:i:s') ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-muted block">Attempts</span>
                            <span class="text-main font-mono">{{ $selectedJob->attempts }} / {{ $selectedJob->max_attempts }}</span>
                        </div>
                    </div>

                    <!-- Logs Stream -->
                    <div>
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-muted mb-2">Agent Execution Logs</h4>
                        <div class="bg-[var(--bg-shell)] rounded-[16px] p-3.5 border border-border font-mono text-xs space-y-1.5 max-h-60 overflow-y-auto">
                            @forelse ($selectedJob->logs as $log)
                                <div class="flex items-start space-x-2">
                                    <span class="text-slate-600 select-none text-[10px]">{{ $log->created_at->format('H:i:s') }}</span>
                                    <span class="px-1.5 rounded-full text-[10px] uppercase font-bold {{ $log->level === 'error' ? 'bg-rose-950 text-rose-400' : ($log->level === 'warn' ? 'bg-amber-950 text-amber-400' : 'bg-slate-800 text-slate-400') }}">
                                        {{ $log->level }}
                                    </span>
                                    <span class="{{ $log->level === 'error' ? 'text-rose-300' : 'text-slate-300' }} break-all">
                                        {{ $log->message }}
                                    </span>
                                </div>
                            @empty
                                <div class="text-slate-600 italic">No logs recorded yet for this job.</div>
                            @endforelse
                        </div>
                    </div>

                    <!-- Structured Payload & Result -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                        <div>
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-muted mb-1">Payload</h4>
                            <pre class="bg-shell p-3 rounded-[16px] border border-border text-main font-mono overflow-x-auto max-h-48">{{ json_encode($selectedJob->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                        <div>
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-muted mb-1">Result Data</h4>
                            <pre class="bg-shell p-3 rounded-[16px] border border-border text-main font-mono overflow-x-auto max-h-48">{{ json_encode($selectedJob->result ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="border-t border-border pt-4 mt-4 flex items-center justify-between">
                    <div>
                        @if($selectedJob->status->value === 'failed')
                            <x-ui.button wire:click="retryJob('{{ $selectedJob->id }}')" variant="danger" size="sm">
                                Re-queue Job
                            </x-ui.button>
                        @endif
                    </div>
                    <x-ui.button wire:click="closeModal" variant="outline" size="sm">
                        Close
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
