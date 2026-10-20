<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">Audit Logs</h2>
            <p class="text-sm text-slate-400 mt-1">Immutable trail of administrative and account state transitions.</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-6 grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Search Keyword / IP / User</label>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Filter logs..."
                class="w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border">
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Action Type</label>
            <input type="text" wire:model.live.debounce.300ms="action" placeholder="e.g. auth., user."
                class="w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border">
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Date From</label>
            <input type="date" wire:model.live="dateFrom"
                class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border">
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Date To</label>
            <input type="date" wire:model.live="dateTo"
                class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border">
        </div>
    </div>

    <!-- Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                <tr>
                    <th scope="col" class="px-6 py-3">Timestamp</th>
                    <th scope="col" class="px-6 py-3">Actor</th>
                    <th scope="col" class="px-6 py-3">Action</th>
                    <th scope="col" class="px-6 py-3">Target</th>
                    <th scope="col" class="px-6 py-3">IP Address</th>
                    <th scope="col" class="px-6 py-3 text-right">Payload</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800 text-slate-300">
                @forelse ($logs as $log)
                    <tr class="hover:bg-slate-800/30 transition">
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-slate-400 font-mono">
                            {{ $log->created_at->format('Y-m-d H:i:s') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if ($log->user)
                                <span class="font-medium text-white">{{ $log->user->name }}</span>
                                <span class="block text-xs text-slate-500">{{ $log->user->email }}</span>
                            @else
                                <span class="text-xs text-slate-500 italic">System / Unauthenticated</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-medium bg-slate-800 text-indigo-400 border border-slate-700">
                                {{ $log->action }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-slate-400">
                            {{ $log->target_type ? class_basename($log->target_type) . '#' . $log->target_id : '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-slate-400">
                            {{ $log->ip_address ?? '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                            @if ($log->old_values || $log->new_values)
                                <button type="button" wire:click="viewDetails({{ $log->id }})"
                                    class="text-indigo-400 hover:text-indigo-300 font-medium underline">
                                    View Diff
                                </button>
                            @else
                                <span class="text-slate-600">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-slate-500 text-sm">
                            No audit log entries matching your criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($logs->hasPages())
            <div class="px-6 py-4 border-t border-slate-800">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

    <!-- Details Modal -->
    @if ($selectedLog)
        <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-2xl w-full p-6 shadow-2xl overflow-hidden">
                <div class="flex items-center justify-between pb-4 border-b border-slate-800">
                    <div>
                        <h3 class="text-lg font-bold text-white">Audit Log Details #{{ $selectedLog->id }}</h3>
                        <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $selectedLog->action }} &middot; {{ $selectedLog->created_at->format('Y-m-d H:i:s') }}</p>
                    </div>
                    <button type="button" wire:click="closeDetails" class="text-slate-400 hover:text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <div class="mt-4 space-y-4 max-h-96 overflow-y-auto">
                    @if ($selectedLog->old_values)
                        <div>
                            <h4 class="text-xs font-semibold text-rose-400 uppercase tracking-wider mb-1">Previous Values</h4>
                            <pre class="bg-slate-950 p-3 rounded-lg border border-slate-800 text-xs font-mono text-slate-300 overflow-x-auto">{{ json_encode($selectedLog->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endif

                    @if ($selectedLog->new_values)
                        <div>
                            <h4 class="text-xs font-semibold text-emerald-400 uppercase tracking-wider mb-1">New Values</h4>
                            <pre class="bg-slate-950 p-3 rounded-lg border border-slate-800 text-xs font-mono text-slate-300 overflow-x-auto">{{ json_encode($selectedLog->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endif
                </div>

                <div class="mt-6 pt-4 border-t border-slate-800 text-right">
                    <button type="button" wire:click="closeDetails"
                        class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium rounded-lg border border-slate-700">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

