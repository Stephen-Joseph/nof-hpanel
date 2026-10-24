<div class="space-y-6">
    <x-layout.page-header 
        title="API Request Logs" 
        subtitle="Real-time access telemetry and audit records for REST API invocations."
    >
        <x-slot:actions>
            <x-ui.button wire:click="exportCsv" variant="outline" size="sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Export CSV
            </x-ui.button>
            <x-ui.button href="{{ route('api.integration.overview') }}" variant="outline" size="sm">
                &larr; Overview
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Filters Toolbar -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Token Filter -->
        <div>
            <label class="block text-xs font-medium text-[var(--text-muted)] mb-1.5">API Token</label>
            <x-ui.select wire:model.live="tokenFilter">
                <option value="">All Tokens</option>
                @foreach ($availableTokens as $tok)
                    <option value="{{ $tok->id }}">{{ $tok->name }} ({{ $tok->prefix }}...)</option>
                @endforeach
            </x-ui.select>
        </div>

        <!-- Method Filter -->
        <div>
            <label class="block text-xs font-medium text-[var(--text-muted)] mb-1.5">HTTP Method</label>
            <x-ui.select wire:model.live="methodFilter">
                <option value="">All Methods</option>
                <option value="GET">GET</option>
                <option value="POST">POST</option>
                <option value="PUT">PUT</option>
                <option value="PATCH">PATCH</option>
                <option value="DELETE">DELETE</option>
            </x-ui.select>
        </div>

        <!-- Status Filter -->
        <div>
            <label class="block text-xs font-medium text-[var(--text-muted)] mb-1.5">HTTP Status</label>
            <x-ui.select wire:model.live="statusFilter">
                <option value="">All Statuses</option>
                <option value="2xx">2xx Success</option>
                <option value="3xx">3xx Redirection</option>
                <option value="4xx">4xx Client Error</option>
                <option value="5xx">5xx Server Error</option>
            </x-ui.select>
        </div>

        <!-- Date Range Filter -->
        <div>
            <label class="block text-xs font-medium text-[var(--text-muted)] mb-1.5">Time Window</label>
            <x-ui.select wire:model.live="dateRange">
                <option value="today">Today</option>
                <option value="yesterday">Yesterday</option>
                <option value="7days">Last 7 Days</option>
                <option value="30days">Last 30 Days</option>
                <option value="all">All Time</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Telemetry Table -->
    <x-ui.card class="p-0 overflow-hidden">
        @if ($logs->isEmpty())
            <div class="p-8">
                <x-ui.empty-state
                    title="No request logs found"
                    description="No telemetry matching the selected filters was recorded."
                />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="border-b border-[var(--border)] bg-[var(--bg-shell)] text-[var(--text-muted)] uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="py-3 px-4 font-semibold">Method</th>
                            <th class="py-3 px-4 font-semibold">Path</th>
                            <th class="py-3 px-4 font-semibold">Status</th>
                            <th class="py-3 px-4 font-semibold">Token</th>
                            <th class="py-3 px-4 font-semibold">Latency</th>
                            <th class="py-3 px-4 font-semibold">Source IP</th>
                            <th class="py-3 px-4 font-semibold text-right">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)] text-[var(--text)]">
                        @foreach ($logs as $log)
                            <tr 
                                wire:click="viewDetails({{ $log->id }})" 
                                class="hover:bg-[var(--bg-shell)] transition-colors cursor-pointer"
                                title="Click to view telemetry details"
                            >
                                <td class="py-3 px-4">
                                    <x-ui.chip :variant="match($log->method) {
                                        'GET' => 'success',
                                        'POST' => 'primary',
                                        'DELETE' => 'danger',
                                        default => 'neutral'
                                    }">
                                        {{ $log->method }}
                                    </x-ui.chip>
                                </td>
                                <td class="py-3 px-4 text-xs font-semibold text-[var(--text)]">
                                    {{ $log->path }}
                                </td>
                                <td class="py-3 px-4">
                                    <span @class([
                                        'px-2 py-0.5 rounded text-[11px] font-bold font-mono',
                                        'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' => $log->status_code < 300,
                                        'bg-amber-500/10 text-amber-600 dark:text-amber-400' => $log->status_code >= 300 && $log->status_code < 400,
                                        'bg-rose-500/10 text-rose-600 dark:text-rose-400' => $log->status_code >= 400,
                                    ])>
                                        {{ $log->status_code }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-[var(--text-muted)]">
                                    {{ $log->token?->name ?? ($log->token?->prefix ? $log->token->prefix.'...' : '&mdash;') }}
                                </td>
                                <td class="py-3 px-4 text-[var(--text-muted)]">
                                    {{ $log->duration_ms !== null ? "{$log->duration_ms}ms" : '&mdash;' }}
                                </td>
                                <td class="py-3 px-4 text-[var(--text-muted)]">
                                    {{ $log->ip_address ?? '&mdash;' }}
                                </td>
                                <td class="py-3 px-4 text-right text-[var(--text-muted)]">
                                    {{ $log->created_at->format('M d, H:i:s') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-[var(--border)]">
                {{ $logs->links() }}
            </div>
        @endif
    </x-ui.card>

    <!-- Side Panel / Modal: Request Telemetry Details -->
    <x-ui.modal name="log-details-modal" max-width="lg">
        <div class="p-6 space-y-4">
            <div class="flex items-center justify-between border-b border-[var(--border)] pb-3">
                <div class="flex items-center gap-2">
                    <span class="text-base font-semibold text-[var(--text)]">Telemetry Event</span>
                    @if ($selectedLog)
                        <span class="text-xs font-mono text-[var(--text-muted)]">#{{ $selectedLog->id }}</span>
                    @endif
                </div>
                <x-ui.button type="button" variant="ghost" size="xs" x-on:click="$dispatch('close-modal', 'log-details-modal')">
                    Close
                </x-ui.button>
            </div>

            @if ($selectedLog)
                <div class="space-y-3 font-mono text-xs">
                    <div class="grid grid-cols-3 gap-2 p-2.5 rounded-xl bg-[var(--bg-shell)] border border-[var(--border)]">
                        <span class="text-[var(--text-muted)] font-medium">Method &amp; Path:</span>
                        <span class="col-span-2 font-bold text-[var(--text)]">{{ $selectedLog->method }} {{ $selectedLog->path }}</span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 p-2.5 rounded-xl bg-[var(--bg-shell)] border border-[var(--border)]">
                        <span class="text-[var(--text-muted)] font-medium">HTTP Status:</span>
                        <span class="col-span-2">
                            <span @class([
                                'px-2 py-0.5 rounded text-[11px] font-bold',
                                'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' => $selectedLog->status_code < 300,
                                'bg-rose-500/10 text-rose-600 dark:text-rose-400' => $selectedLog->status_code >= 400,
                            ])>
                                {{ $selectedLog->status_code }}
                            </span>
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 p-2.5 rounded-xl bg-[var(--bg-shell)] border border-[var(--border)]">
                        <span class="text-[var(--text-muted)] font-medium">Response Latency:</span>
                        <span class="col-span-2 text-[var(--text)]">{{ $selectedLog->duration_ms !== null ? "{$selectedLog->duration_ms} ms" : 'N/A' }}</span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 p-2.5 rounded-xl bg-[var(--bg-shell)] border border-[var(--border)]">
                        <span class="text-[var(--text-muted)] font-medium">API Token:</span>
                        <span class="col-span-2 text-[var(--text)]">
                            {{ $selectedLog->token?->name ?? 'None' }} 
                            @if ($selectedLog->token?->prefix)
                                ({{ $selectedLog->token->prefix }}...)
                            @endif
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 p-2.5 rounded-xl bg-[var(--bg-shell)] border border-[var(--border)]">
                        <span class="text-[var(--text-muted)] font-medium">Caller IP:</span>
                        <span class="col-span-2 text-[var(--text)]">{{ $selectedLog->ip_address ?? 'Unknown' }}</span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 p-2.5 rounded-xl bg-[var(--bg-shell)] border border-[var(--border)]">
                        <span class="text-[var(--text-muted)] font-medium">User-Agent:</span>
                        <span class="col-span-2 text-[var(--text)] break-all text-[11px]">{{ $selectedLog->user_agent ?? 'Unknown' }}</span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 p-2.5 rounded-xl bg-[var(--bg-shell)] border border-[var(--border)]">
                        <span class="text-[var(--text-muted)] font-medium">Recorded At:</span>
                        <span class="col-span-2 text-[var(--text)]">{{ $selectedLog->created_at->toRfc2822String() }}</span>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-[var(--bg-shell)] border border-[var(--border)] text-[11px] text-[var(--text-muted)]">
                    <em>Note: In accordance with zero-cleartext security requirements, request/response bodies and authorization headers are never logged.</em>
                </div>
            @endif

            <div class="flex justify-end pt-2 border-t border-[var(--border)]">
                <x-ui.button type="button" variant="primary" size="sm" x-on:click="$dispatch('close-modal', 'log-details-modal')">
                    Done
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>

