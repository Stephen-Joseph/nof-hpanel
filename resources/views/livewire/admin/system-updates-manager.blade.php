<div class="space-y-6">
    <!-- Header & Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-[var(--text)]">System Updates &amp; Auto-Deployment</h1>
            <p class="text-sm text-[var(--text-muted)] mt-1">
                Manage automated GitHub deployments, monitor update runner status, and trigger safe rollbacks.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button variant="secondary" size="sm" wire:click="openRollbackModal" :disabled="empty($availableReleases)">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12.066 11.2a1 1 0 000 1.6l5.334 4A1 1 0 0019 16V8a1 1 0 00-1.6-.8l-5.334 4zM4.066 11.2a1 1 0 000 1.6l5.334 4A1 1 0 0011 16V8a1 1 0 00-1.6-.8l-5.334 4z" />
                </svg>
                Rollback to Previous
            </x-ui.button>

            <x-ui.button variant="primary" size="sm" wire:click="deployNow" loading="deployNow">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
                Deploy Now
            </x-ui.button>
        </div>
    </div>

    <!-- Flash Messages -->
    @if (session()->has('success'))
        <div class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-200 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-rose-600 dark:text-rose-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        </div>
    @endif

    <!-- Migration Alert Warning -->
    @if (($originStatus['has_migrations'] ?? false) || ($latestRun && $latestRun->isPending() && $latestRun->has_migrations))
        <div class="p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-sm flex items-start gap-3">
            <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <div>
                <p class="font-semibold">Pending Release Contains Database Migrations</p>
                <p class="mt-1 text-xs sm:text-sm text-amber-800/90 dark:text-amber-300/90">
                    Database migrations are additive only. If you perform an update and later trigger a rollback, the database schema changes will not be reverted automatically.
                </p>
            </div>
        </div>
    @endif

    <!-- Overview Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Current Version -->
        <div class="p-6 rounded-2xl border border-[var(--border)] bg-[var(--bg-card)] shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Current Release</span>
                    <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                        Active
                    </span>
                </div>
                <div class="mt-3">
                    <div class="font-mono text-lg font-bold text-[var(--text)]">
                        {{ substr($currentRelease['commit'] ?? 'unknown', 0, 7) }}
                    </div>
                    <div class="text-xs text-[var(--text-muted)] mt-1 truncate" title="{{ $currentRelease['message'] ?? '' }}">
                        {{ $currentRelease['message'] ?? 'No message' }}
                    </div>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-[var(--border)] text-[11px] text-[var(--text-muted)] flex items-center justify-between">
                <span>By: {{ $currentRelease['author'] ?? 'System' }}</span>
                <span>{{ isset($currentRelease['deployed_at']) && $currentRelease['deployed_at'] ? \Carbon\Carbon::parse($currentRelease['deployed_at'])->diffForHumans() : 'Live' }}</span>
            </div>
        </div>

        <!-- Remote Version (origin/main) -->
        <div class="p-6 rounded-2xl border border-[var(--border)] bg-[var(--bg-card)] shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">GitHub origin/main</span>
                    @if($originStatus['up_to_date'] ?? true)
                        <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            Up to Date
                        </span>
                    @else
                        <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300">
                            Update Available
                        </span>
                    @endif
                </div>
                <div class="mt-3">
                    <div class="font-mono text-lg font-bold text-[var(--text)]">
                        {{ isset($originStatus['remote_sha']) && $originStatus['remote_sha'] ? substr($originStatus['remote_sha'], 0, 7) : 'origin/main' }}
                    </div>
                    <div class="text-xs text-[var(--text-muted)] mt-1 truncate" title="{{ $originStatus['commit_message'] ?? 'Clean' }}">
                        {{ $originStatus['commit_message'] ?? 'Repository synchronized with remote' }}
                    </div>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-[var(--border)] text-[11px] text-[var(--text-muted)] flex items-center justify-between">
                <span>Branch: main</span>
                <span>{{ isset($originStatus['last_checked_at']) && $originStatus['last_checked_at'] ? \Carbon\Carbon::parse($originStatus['last_checked_at'])->diffForHumans() : '10m poll timer' }}</span>
            </div>
        </div>

        <!-- Latest Deploy Status -->
        <div class="p-6 rounded-2xl border border-[var(--border)] bg-[var(--bg-card)] shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Last Deployment</span>
                    @if(! $latestRun)
                        <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">None</span>
                    @elseif($latestRun->status === 'success')
                        <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">Success</span>
                    @elseif($latestRun->status === 'running')
                        <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300 animate-pulse">Running</span>
                    @elseif($latestRun->status === 'queued')
                        <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">Queued</span>
                    @elseif($latestRun->status === 'rolled_back')
                        <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">Rolled Back</span>
                    @else
                        <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">Failed</span>
                    @endif
                </div>
                <div class="mt-3">
                    @if($latestRun)
                        <div class="font-mono text-lg font-bold text-[var(--text)]">
                            {{ substr($latestRun->commit_sha, 0, 7) }}
                            <span class="text-xs font-normal text-[var(--text-muted)]">({{ ucfirst($latestRun->action) }})</span>
                        </div>
                        <div class="text-xs text-[var(--text-muted)] mt-1 truncate">
                            @if($latestRun->failed_step)
                                Failed at: <span class="font-medium text-rose-500">{{ $latestRun->failed_step }}</span>
                            @else
                                {{ $latestRun->commit_message ?? 'Executed successfully' }}
                            @endif
                        </div>
                    @else
                        <div class="text-sm text-[var(--text-muted)]">No deployments recorded yet.</div>
                    @endif
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-[var(--border)] text-[11px] text-[var(--text-muted)] flex items-center justify-between">
                <span>Duration: {{ $latestRun && $latestRun->duration_seconds ? $latestRun->duration_seconds . 's' : 'N/A' }}</span>
                <span>{{ $latestRun ? $latestRun->created_at->diffForHumans() : '' }}</span>
            </div>
        </div>
    </div>

    <!-- Available Release Snapshots (Last 5) -->
    <div class="p-6 rounded-2xl border border-[var(--border)] bg-[var(--bg-card)] shadow-xs">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-base font-bold text-[var(--text)]">Rollback Snapshots</h2>
                <p class="text-xs text-[var(--text-muted)]">The last 5 previous releases preserved with code commits and matching Go agent binaries.</p>
            </div>
            <span class="text-xs text-[var(--text-muted)] font-mono">{{ count($availableReleases) }}/5 available</span>
        </div>

        @if(empty($availableReleases))
            <div class="py-8 text-center text-sm text-[var(--text-muted)]">
                No rollback snapshots available yet. Snapshots are created automatically before each deployment.
            </div>
        @else
            <div class="divide-y divide-[var(--border)]">
                @foreach($availableReleases as $release)
                    <div class="py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <span class="px-2 py-1 rounded-md bg-[var(--bg-shell)] font-mono text-xs font-semibold text-[var(--text)] border border-[var(--border)]">
                                {{ $release['short_commit'] }}
                            </span>
                            <div>
                                <div class="text-sm font-medium text-[var(--text)]">
                                    {{ $release['message'] }}
                                </div>
                                <div class="text-xs text-[var(--text-muted)] mt-0.5 flex items-center gap-2">
                                    <span>{{ $release['created_at'] }}</span>
                                    <span>&bull;</span>
                                    @if($release['has_agent_snapshot'])
                                        <span class="text-emerald-600 dark:text-emerald-400">Agent binary saved</span>
                                    @else
                                        <span>Code snapshot only</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div>
                            <x-ui.button variant="secondary" size="xs" wire:click="openRollbackModal('{{ $release['commit'] }}')">
                                Revert to this release &rarr;
                            </x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Deployment History -->
    <div class="p-6 rounded-2xl border border-[var(--border)] bg-[var(--bg-card)] shadow-xs">
        <h2 class="text-base font-bold text-[var(--text)] mb-4">Deployment History</h2>

        @if($history->isEmpty())
            <div class="py-12 text-center text-sm text-[var(--text-muted)]">
                No deployment history found. Incoming webhook pushes and manual deploys will be logged here.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-[var(--border)] text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            <th class="pb-3">Timestamp</th>
                            <th class="pb-3">Action</th>
                            <th class="pb-3">Commit</th>
                            <th class="pb-3">Status</th>
                            <th class="pb-3">Duration</th>
                            <th class="pb-3">Step / Logs</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                        @foreach($history as $run)
                            <tr class="hover:bg-[var(--bg-shell)]/40 transition">
                                <td class="py-3 text-xs text-[var(--text)] whitespace-nowrap">
                                    {{ $run->created_at->format('Y-m-d H:i:s') }}
                                    <div class="text-[11px] text-[var(--text-muted)]">{{ $run->created_at->diffForHumans() }}</div>
                                </td>
                                <td class="py-3 whitespace-nowrap">
                                    <span class="px-2 py-0.5 text-xs rounded font-medium {{ $run->action === 'rollback' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' }}">
                                        {{ ucfirst($run->action) }}
                                    </span>
                                </td>
                                <td class="py-3">
                                    <div class="font-mono text-xs font-semibold text-[var(--text)]">
                                        {{ substr($run->commit_sha, 0, 7) }}
                                    </div>
                                    <div class="text-xs text-[var(--text-muted)] truncate max-w-xs" title="{{ $run->commit_message }}">
                                        {{ $run->commit_message ?: 'Deployment' }}
                                    </div>
                                </td>
                                <td class="py-3 whitespace-nowrap">
                                    @if($run->status === 'success')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                            Success
                                        </span>
                                    @elseif($run->status === 'running')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300 animate-pulse">
                                            Running...
                                        </span>
                                    @elseif($run->status === 'queued')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                            Queued
                                        </span>
                                    @elseif($run->status === 'rolled_back')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">
                                            Rolled Back
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">
                                            Failed
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 text-xs text-[var(--text-muted)] whitespace-nowrap">
                                    {{ $run->duration_seconds ? $run->duration_seconds . 's' : '-' }}
                                </td>
                                <td class="py-3 text-xs text-[var(--text-muted)]">
                                    @if($run->failed_step)
                                        <span class="text-rose-600 dark:text-rose-400 font-medium">{{ $run->failed_step }}</span>
                                    @endif
                                    @if($run->log_tail)
                                        <button wire:click="openLogModal({{ $run->id }})" class="text-blue-600 dark:text-blue-400 hover:underline ml-2 text-xs">
                                            View Logs &rarr;
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $history->links() }}
            </div>
        @endif
    </div>

    <!-- Rollback Confirmation Modal -->
    @if ($showingRollbackModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-xs transition-opacity" wire:click="closeRollbackModal"></div>

                <div class="inline-block align-bottom bg-[var(--bg-card)] rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-[var(--border)]">
                    <div class="p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full bg-rose-100 dark:bg-rose-900/40 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-[var(--text)]" id="modal-title">Confirm Release Rollback</h3>
                                <p class="text-xs text-[var(--text-muted)]">Target release: <span class="font-mono font-semibold">{{ substr($confirmTargetSha, 0, 7) }}</span></p>
                            </div>
                        </div>

                        <div class="space-y-3 text-sm text-[var(--text-muted)]">
                            <p>
                                Rollback will restore the codebase to commit <strong class="text-[var(--text)] font-mono">{{ $confirmTargetSha }}</strong> and reinstall the previous Go server agent binary.
                            </p>
                            <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-xs">
                                <strong>Important notice:</strong> Database migrations are additive only. Rollback does not reverse database changes automatically. Any database tables or columns added by recent migrations will remain in MariaDB.
                            </div>
                            <p class="text-xs">
                                The system update runner takes an exclusive lock during rollback and restarts PHP-FPM, the Queue worker, and the Agent service gracefully.
                            </p>
                        </div>
                    </div>

                    <div class="px-6 py-4 bg-[var(--bg-shell)]/60 border-t border-[var(--border)] flex justify-end gap-3">
                        <x-ui.button variant="outline" size="sm" wire:click="closeRollbackModal">
                            Cancel
                        </x-ui.button>
                        <x-ui.button variant="danger" size="sm" wire:click="executeRollback">
                            Confirm Rollback
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Log Modal -->
    @if ($showingLogModal && $selectedRun)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="log-modal-title" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-xs transition-opacity" wire:click="closeLogModal"></div>

                <div class="inline-block align-bottom bg-[var(--bg-card)] rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full border border-[var(--border)]">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-base font-bold text-[var(--text)]" id="log-modal-title">
                                Deployment Output Tail &middot; Commit {{ substr($selectedRun->commit_sha, 0, 7) }}
                            </h3>
                            <button wire:click="closeLogModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">&times;</button>
                        </div>

                        <div class="p-4 rounded-xl bg-slate-950 text-slate-200 font-mono text-xs overflow-x-auto max-h-96 whitespace-pre-wrap">
{{ $selectedRun->log_tail ?: 'No detailed log excerpt available.' }}
                        </div>
                    </div>

                    <div class="px-6 py-3 bg-[var(--bg-shell)]/60 border-t border-[var(--border)] flex justify-end">
                        <x-ui.button variant="outline" size="sm" wire:click="closeLogModal">
                            Close
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

