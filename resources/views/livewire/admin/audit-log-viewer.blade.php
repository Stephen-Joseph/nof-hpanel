<div>
    <x-layout.page-header 
        title="Audit Logs" 
        subtitle="Immutable trail of administrative and account state transitions."
    />

    <!-- Filters -->
    <x-ui.card class="mb-6 grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div>
            <label class="block text-xs font-medium text-muted mb-1.5">Search Keyword / IP / User</label>
            <x-ui.input type="text" wire:model.live.debounce.300ms="search" placeholder="Filter logs..." />
        </div>

        <div>
            <label class="block text-xs font-medium text-muted mb-1.5">Action Type</label>
            <x-ui.input type="text" wire:model.live.debounce.300ms="action" placeholder="e.g. auth., user." />
        </div>

        <div>
            <label class="block text-xs font-medium text-muted mb-1.5">Date From</label>
            <x-ui.input type="date" wire:model.live="dateFrom" />
        </div>

        <div>
            <label class="block text-xs font-medium text-muted mb-1.5">Date To</label>
            <x-ui.input type="date" wire:model.live="dateTo" />
        </div>
    </x-ui.card>

    <!-- Table -->
    <x-ui.card class="p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="py-3 px-6">Timestamp</th>
                        <th class="py-3 px-6">Actor</th>
                        <th class="py-3 px-6">Action</th>
                        <th class="py-3 px-6">Target</th>
                        <th class="py-3 px-6">IP Address</th>
                        <th class="py-3 px-6 text-right">Payload</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-main">
                    @forelse ($logs as $log)
                        <tr class="hover:bg-shell/40 transition">
                            <td class="px-6 py-4 whitespace-nowrap text-xs text-muted font-mono">
                                {{ $log->created_at->format('Y-m-d H:i:s') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if ($log->user)
                                    <span class="font-medium text-main">{{ $log->user->name }}</span>
                                    <span class="block text-xs text-muted">{{ $log->user->email }}</span>
                                @else
                                    <span class="text-xs text-muted italic">System / Unauthenticated</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-medium bg-shell text-primary-500 border border-border">
                                    {{ $log->action }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-muted">
                                {{ $log->target_type ? class_basename($log->target_type) . '#' . $log->target_id : '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-muted">
                                {{ $log->ip_address ?? '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                                @if ($log->old_values || $log->new_values)
                                    <button type="button" wire:click="viewDetails({{ $log->id }})"
                                        class="text-primary-500 hover:text-primary-600 font-medium underline cursor-pointer">
                                        View Diff
                                    </button>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-muted text-xs">
                                No audit log entries matching your criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="px-6 py-4 border-t border-border">
                {{ $logs->links() }}
            </div>
        @endif
    </x-ui.card>

    <!-- Details Modal -->
    @if ($selectedLog)
        <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-2xl w-full p-6 shadow-2xl overflow-hidden">
                <div class="flex items-center justify-between pb-4 border-b border-border">
                    <div>
                        <h3 class="text-lg font-bold text-main">Audit Log Details #{{ $selectedLog->id }}</h3>
                        <p class="text-xs text-muted font-mono mt-0.5">{{ $selectedLog->action }} &middot; {{ $selectedLog->created_at->format('Y-m-d H:i:s') }}</p>
                    </div>
                    <button type="button" wire:click="closeDetails" class="text-muted hover:text-main text-lg cursor-pointer">
                        &times;
                    </button>
                </div>

                <div class="mt-4 space-y-4 max-h-96 overflow-y-auto">
                    @if ($selectedLog->old_values)
                        <div>
                            <h4 class="text-xs font-semibold text-rose-500 uppercase tracking-wider mb-1">Previous Values</h4>
                            <pre class="bg-shell p-3 rounded-[16px] border border-border text-xs font-mono text-main overflow-x-auto">{{ json_encode($selectedLog->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endif

                    @if ($selectedLog->new_values)
                        <div>
                            <h4 class="text-xs font-semibold text-emerald-500 uppercase tracking-wider mb-1">New Values</h4>
                            <pre class="bg-shell p-3 rounded-[16px] border border-border text-xs font-mono text-main overflow-x-auto">{{ json_encode($selectedLog->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endif
                </div>

                <div class="mt-6 pt-4 border-t border-border text-right">
                    <x-ui.button type="button" wire:click="closeDetails" variant="outline" size="sm">
                        Close
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
