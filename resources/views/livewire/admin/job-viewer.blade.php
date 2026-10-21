<div @if($autoRefresh) wire:poll.3s @endif>
    <!-- Header -->
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">Agent Job Protocol Monitor</h2>
            <p class="text-sm text-slate-400 mt-1">Real-time status of signed agent actions, server executions, and log streams.</p>
        </div>
        <div class="mt-4 sm:mt-0 flex items-center space-x-3">
            <label class="flex items-center text-xs text-slate-400 cursor-pointer bg-slate-900 border border-slate-800 px-3 py-2 rounded-lg">
                <input type="checkbox" wire:model.live="autoRefresh" class="rounded bg-slate-800 border-slate-700 text-indigo-600 mr-2 focus:ring-0">
                Auto-refresh (3s)
            </label>

            @if($servers->isNotEmpty())
                <div x-data="{ open: false }" class="relative inline-block text-left">
                    <button @click="open = !open" type="button" class="inline-flex items-center px-3.5 py-2 border border-slate-700 rounded-lg text-sm font-medium text-white bg-slate-800 hover:bg-slate-700 focus:outline-none">
                        <svg class="w-4 h-4 mr-2 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Test Server Ping
                    </button>
                    <div x-show="open" @click.away="open = false" class="origin-top-right absolute right-0 mt-2 w-56 rounded-md shadow-lg bg-slate-800 border border-slate-700 ring-1 ring-black ring-opacity-5 z-20">
                        <div class="py-1">
                            @foreach($servers as $srv)
                                <button wire:click="dispatchPing({{ $srv->id }})" @click="open = false" class="block w-full text-left px-4 py-2 text-sm text-slate-200 hover:bg-slate-700 hover:text-white">
                                    {{ $srv->name }} ({{ $srv->hostname }})
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if (session()->has('status'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400 flex items-center justify-between">
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <!-- Filters -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Search Action / Job ID / Server</label>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Filter action, UUID or server..."
                class="w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Status</label>
            <select wire:model.live="status" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="running">Running</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Server</label>
            <select wire:model.live="serverId" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                <option value="">All Servers</option>
                @foreach ($servers as $srv)
                    <option value="{{ $srv->id }}">{{ $srv->name }} ({{ $srv->hostname }})</option>
                @endforeach
            </select>
        </div>
    </div>

    <!-- Jobs Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                <tr>
                    <th scope="col" class="px-6 py-3">Job ID / Time</th>
                    <th scope="col" class="px-6 py-3">Server</th>
                    <th scope="col" class="px-6 py-3">Action</th>
                    <th scope="col" class="px-6 py-3">Status</th>
                    <th scope="col" class="px-6 py-3">Attempts</th>
                    <th scope="col" class="px-6 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800 text-slate-300">
                @forelse ($jobs as $job)
                    <tr class="hover:bg-slate-800/30 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="font-mono text-xs text-indigo-400 block truncate max-w-xs">{{ $job->id }}</span>
                            <span class="text-xs text-slate-500">{{ $job->created_at?->diffForHumans() }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="font-medium text-white">{{ $job->server->name }}</div>
                            <div class="text-xs text-slate-400 font-mono">{{ $job->server->hostname }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2 py-0.5 rounded font-mono text-xs font-semibold bg-slate-800 text-slate-200 border border-slate-700">
                                {{ $job->action }}
                            </span>
                            @if($job->dry_run)
                                <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                    dry-run
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @php
                                $colorClasses = match ($job->status->value) {
                                    'pending' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
                                    'running' => 'bg-blue-500/10 text-blue-400 border-blue-500/20 animate-pulse',
                                    'completed' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                                    'failed' => 'bg-rose-500/10 text-rose-400 border-rose-500/20',
                                    default => 'bg-slate-800 text-slate-400 border-slate-700',
                                };
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border {{ $colorClasses }}">
                                {{ $job->status->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-slate-400">
                            {{ $job->attempts }} / {{ $job->max_attempts }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs space-x-2">
                            <button wire:click="viewJob('{{ $job->id }}')" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded border border-slate-700 transition">
                                View Logs
                            </button>
                            @if($job->status->value === 'failed')
                                <button wire:click="retryJob('{{ $job->id }}')" class="px-2.5 py-1.5 bg-rose-950/60 hover:bg-rose-900 text-rose-300 rounded border border-rose-800 transition">
                                    Retry
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-slate-500 text-sm">
                            No agent jobs found matching the current criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $jobs->links() }}
    </div>

    <!-- Job Details / Logs Modal -->
    @if ($selectedJob)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-4xl w-full p-6 shadow-2xl relative max-h-[90vh] flex flex-col">
                <!-- Modal Header -->
                <div class="flex items-start justify-between border-b border-slate-800 pb-4 mb-4">
                    <div>
                        <div class="flex items-center space-x-2">
                            <h3 class="text-lg font-bold text-white">{{ $selectedJob->action }}</h3>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-medium border {{ match ($selectedJob->status->value) {
                                'pending' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
                                'running' => 'bg-blue-500/10 text-blue-400 border-blue-500/20',
                                'completed' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                                'failed' => 'bg-rose-500/10 text-rose-400 border-rose-500/20',
                                default => 'bg-slate-800 text-slate-400',
                            } }}">
                                {{ $selectedJob->status->label() }}
                            </span>
                            @if($selectedJob->dry_run)
                                <span class="px-1.5 py-0.5 rounded text-[10px] bg-amber-500/10 text-amber-400 border border-amber-500/20">dry-run</span>
                            @endif
                        </div>
                        <p class="text-xs text-slate-400 font-mono mt-1">ID: {{ $selectedJob->id }} &bull; Server: {{ $selectedJob->server->name }} ({{ $selectedJob->server->hostname }})</p>
                    </div>
                    <button wire:click="closeModal" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
                </div>

                <!-- Modal Body -->
                <div class="overflow-y-auto space-y-4 flex-1 pr-2">
                    <!-- Error Alert -->
                    @if ($selectedJob->error_message)
                        <div class="rounded-lg bg-rose-500/10 border border-rose-500/20 p-3 text-xs text-rose-400 font-mono">
                            <span class="font-bold">Error:</span> {{ $selectedJob->error_message }}
                        </div>
                    @endif

                    <!-- Execution Timing -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs bg-slate-800/40 p-3 rounded-lg border border-slate-800">
                        <div>
                            <span class="text-slate-500 block">Created</span>
                            <span class="text-slate-300 font-mono">{{ $selectedJob->created_at?->format('H:i:s') }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 block">Started</span>
                            <span class="text-slate-300 font-mono">{{ $selectedJob->started_at?->format('H:i:s') ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 block">Completed</span>
                            <span class="text-slate-300 font-mono">{{ $selectedJob->completed_at?->format('H:i:s') ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 block">Attempts</span>
                            <span class="text-slate-300 font-mono">{{ $selectedJob->attempts }} / {{ $selectedJob->max_attempts }}</span>
                        </div>
                    </div>

                    <!-- Logs Stream -->
                    <div>
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Agent Execution Logs</h4>
                        <div class="bg-black/60 rounded-xl p-3 border border-slate-800 font-mono text-xs space-y-1.5 max-h-60 overflow-y-auto">
                            @forelse ($selectedJob->logs as $log)
                                <div class="flex items-start space-x-2">
                                    <span class="text-slate-600 select-none text-[10px]">{{ $log->created_at->format('H:i:s') }}</span>
                                    <span class="px-1 rounded text-[10px] uppercase font-bold {{ $log->level === 'error' ? 'bg-rose-950 text-rose-400' : ($log->level === 'warn' ? 'bg-amber-950 text-amber-400' : 'bg-slate-800 text-slate-400') }}">
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
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Payload</h4>
                            <pre class="bg-black/50 p-3 rounded-lg border border-slate-800 text-slate-300 font-mono overflow-x-auto max-h-48">{{ json_encode($selectedJob->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                        <div>
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Result Data</h4>
                            <pre class="bg-black/50 p-3 rounded-lg border border-slate-800 text-slate-300 font-mono overflow-x-auto max-h-48">{{ json_encode($selectedJob->result ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="border-t border-slate-800 pt-4 mt-4 flex items-center justify-between">
                    <div>
                        @if($selectedJob->status->value === 'failed')
                            <button wire:click="retryJob('{{ $selectedJob->id }}')" class="px-3 py-1.5 bg-rose-600 hover:bg-rose-500 text-white rounded-lg text-xs font-medium transition">
                                Re-queue Job
                            </button>
                        @endif
                    </div>
                    <button wire:click="closeModal" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs rounded-lg transition font-medium">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
