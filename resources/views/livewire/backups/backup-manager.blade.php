<div>
    <!-- Header -->
    <x-layout.page-header 
        title="Backups &amp; Snapshots &middot; {{ $account->primary_domain }}" 
        subtitle="Encrypted, deduplicated snapshots powered by restic. Restore full account, files, or individual databases."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('accounts.show', $account) }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Account
                </x-ui.button>
                <x-ui.button wire:click="createManualBackup" wire:loading.attr="disabled" variant="primary" size="sm">
                    <span wire:loading.remove wire:target="createManualBackup">⚡ Create Backup Now</span>
                    <span wire:loading wire:target="createManualBackup">Creating Snapshot...</span>
                </x-ui.button>
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
    @error('manual')
        <div class="mb-6 rounded-[20px] bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-500 dark:text-rose-400">
            {{ $message }}
        </div>
    @enderror

    <!-- Retention Banner -->
    <x-ui.card class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-950/40 text-primary-500 flex items-center justify-center font-bold text-lg">
                🛡️
            </div>
            <div>
                <h4 class="text-xs font-semibold text-main uppercase tracking-wider">Package Retention Policy</h4>
                <p class="text-xs text-muted mt-0.5">
                    Your hosting package keeps the last <strong class="text-primary-500">{{ $account->package->backups_retained }}</strong> automated snapshots before pruning. Manual backups remain until deleted.
                </p>
            </div>
        </div>
        <div class="text-xs font-mono text-muted bg-shell px-3 py-1.5 rounded-full border border-border">
            Snapshots: <strong class="text-main">{{ $backups->count() }}</strong>
        </div>
    </x-ui.card>

    <!-- Automated Schedule Card -->
    <x-ui.card class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
            <div>
                <h3 class="text-base font-semibold text-main">Automated Schedule Configuration</h3>
                <p class="text-xs text-muted mt-0.5">Configure when recurring restic snapshots are triggered automatically by the system scheduler.</p>
            </div>
            <label class="inline-flex items-center gap-2 text-xs font-medium text-main cursor-pointer">
                <input type="checkbox" wire:model="scheduleEnabled" class="rounded-sm border-border bg-card text-primary-500 focus:ring-primary-500">
                <span>Enable Schedule</span>
            </label>
        </div>

        <form wire:submit.prevent="saveSchedule" class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
            <div>
                <label class="block text-xs font-medium text-muted mb-1.5">Frequency</label>
                <x-ui.select wire:model.live="scheduleFrequency">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                </x-ui.select>
            </div>

            <div>
                <label class="block text-xs font-medium text-muted mb-1.5">Time (UTC)</label>
                <x-ui.input type="text" wire:model="scheduleTime" placeholder="02:00" class="font-mono" />
            </div>

            @if ($scheduleFrequency === 'weekly')
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Day of Week</label>
                    <x-ui.select wire:model="scheduleDayOfWeek">
                        <option value="0">Sunday</option>
                        <option value="1">Monday</option>
                        <option value="2">Tuesday</option>
                        <option value="3">Wednesday</option>
                        <option value="4">Thursday</option>
                        <option value="5">Friday</option>
                        <option value="6">Saturday</option>
                    </x-ui.select>
                </div>
            @elseif ($scheduleFrequency === 'monthly')
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Day of Month</label>
                    <x-ui.input type="number" min="1" max="31" wire:model="scheduleDayOfMonth" class="font-mono" />
                </div>
            @else
                <div></div>
            @endif

            <div>
                <x-ui.button type="submit" variant="outline" class="w-full">
                    Save Schedule Policy
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <!-- Snapshots Table -->
    <x-ui.card class="p-0 overflow-hidden">
        <div class="px-6 py-4 border-b border-border flex justify-between items-center">
            <h3 class="text-sm font-semibold text-main">Snapshot Archive</h3>
            <span class="text-xs text-muted">{{ $backups->count() }} snapshot(s)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="py-3 px-6">Snapshot ID</th>
                        <th class="py-3 px-6">Date / Time</th>
                        <th class="py-3 px-6">Type</th>
                        <th class="py-3 px-6">Size / Files</th>
                        <th class="py-3 px-6">Status</th>
                        <th class="py-3 px-6 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-main">
                    @forelse ($backups as $item)
                        <tr class="hover:bg-shell/40 transition-colors">
                            <td class="px-6 py-4 font-mono text-xs font-bold">
                                @if ($item->snapshot_id)
                                    <span class="bg-shell px-2.5 py-1 rounded-full border border-border text-primary-500">
                                        {{ $item->shortSnapshotId() }}
                                    </span>
                                @else
                                    <span class="text-muted italic">In progress</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-xs font-mono text-muted">
                                {{ $item->created_at->format('Y-m-d H:i') }}
                            </td>
                            <td class="px-6 py-4 text-xs">
                                <x-ui.chip :variant="$item->backup_type === 'scheduled' ? 'info' : 'default'" size="sm">
                                    {{ ucfirst($item->backup_type) }}
                                </x-ui.chip>
                            </td>
                            <td class="px-6 py-4 text-xs font-mono text-muted">
                                {{ $item->formattedSize() }} &middot; {{ $item->file_count }} files
                                @if (!empty($item->databases_included))
                                    <div class="text-[11px] text-muted/80 font-sans mt-0.5">
                                        {{ count($item->databases_included) }} database(s) included
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <x-ui.chip :variant="
                                    $item->status === 'completed' ? 'success' : (
                                    $item->status === 'failed' ? 'danger' : 'warning'
                                )" size="sm">
                                    {{ ucfirst($item->status) }}
                                </x-ui.chip>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-xs space-x-3">
                                @if ($item->isCompleted())
                                    <button type="button" wire:click="openRestoreWizard({{ $item->id }})"
                                        class="text-emerald-500 hover:text-emerald-600 font-medium transition cursor-pointer">
                                        Restore...
                                    </button>
                                @endif
                                <button type="button" wire:click="confirmDelete({{ $item->id }})"
                                    class="text-rose-500 hover:text-rose-600 font-medium transition cursor-pointer">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-muted text-xs">
                                No backup snapshots created yet. Click "Create Backup Now" to create your first restic snapshot.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <!-- Restore Wizard Modal -->
    @if ($showingRestoreModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-xl w-full p-6 shadow-2xl">
                <form wire:submit.prevent="executeRestore">
                    <div class="flex justify-between items-center pb-4 border-b border-border mb-4">
                        <div>
                            <h3 class="text-base font-semibold text-main">Restore Snapshot</h3>
                            <p class="text-xs text-muted font-mono mt-0.5">Snapshot: {{ substr($restoreSnapshotId, 0, 12) }}</p>
                        </div>
                        <button type="button" wire:click="closeRestoreWizard" class="text-muted hover:text-main text-lg cursor-pointer">&times;</button>
                    </div>

                    <div class="space-y-4">
                        <!-- Warning Notice -->
                        <div class="rounded-[16px] bg-amber-500/10 border border-amber-500/20 p-4 text-amber-600 dark:text-amber-300 text-xs">
                            <strong class="font-semibold block mb-1">⚠️ Warning: Data Overwrite Notice</strong>
                            Restoring will overwrite existing files or database contents matching the selected scope. Any changes made since this snapshot was created will be permanently replaced.
                        </div>

                        <!-- Restore Scope Selector -->
                        <div>
                            <label class="block text-xs font-semibold text-muted mb-2 uppercase tracking-wider">Select Restore Scope</label>
                            <div class="space-y-2">
                                <label class="flex items-start gap-3 p-3 rounded-[16px] border border-border bg-shell cursor-pointer hover:border-primary-500/50 transition">
                                    <input type="radio" wire:model.live="restoreType" value="full" class="mt-0.5 text-primary-500 focus:ring-primary-500">
                                    <div>
                                        <div class="text-xs font-semibold text-main">Full Account Restore</div>
                                        <div class="text-[11px] text-muted">Reverts all files and all MariaDB databases back to snapshot state.</div>
                                    </div>
                                </label>

                                <label class="flex items-start gap-3 p-3 rounded-[16px] border border-border bg-shell cursor-pointer hover:border-primary-500/50 transition">
                                    <input type="radio" wire:model.live="restoreType" value="files_only" class="mt-0.5 text-primary-500 focus:ring-primary-500">
                                    <div>
                                        <div class="text-xs font-semibold text-main">Files Only</div>
                                        <div class="text-[11px] text-muted">Reverts /home/{{ $account->linux_username }}/ without modifying MariaDB databases.</div>
                                    </div>
                                </label>

                                <label class="flex items-start gap-3 p-3 rounded-[16px] border border-border bg-shell cursor-pointer hover:border-primary-500/50 transition">
                                    <input type="radio" wire:model.live="restoreType" value="databases_only" class="mt-0.5 text-primary-500 focus:ring-primary-500">
                                    <div>
                                        <div class="text-xs font-semibold text-main">Databases Only</div>
                                        <div class="text-[11px] text-muted">Restores all MySQL/MariaDB databases without touching website files.</div>
                                    </div>
                                </label>

                                <label class="flex items-start gap-3 p-3 rounded-[16px] border border-border bg-shell cursor-pointer hover:border-primary-500/50 transition">
                                    <input type="radio" wire:model.live="restoreType" value="single_db" class="mt-0.5 text-primary-500 focus:ring-primary-500">
                                    <div>
                                        <div class="text-xs font-semibold text-main">Single Database</div>
                                        <div class="text-[11px] text-muted">Reverts only one specific database.</div>
                                    </div>
                                </label>

                                <label class="flex items-start gap-3 p-3 rounded-[16px] border border-border bg-shell cursor-pointer hover:border-primary-500/50 transition">
                                    <input type="radio" wire:model.live="restoreType" value="single_path" class="mt-0.5 text-primary-500 focus:ring-primary-500">
                                    <div>
                                        <div class="text-xs font-semibold text-main">Specific File or Directory</div>
                                        <div class="text-[11px] text-muted">Restores only a specified subfolder or file path.</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Single DB Selector -->
                        @if ($restoreType === 'single_db')
                            <div>
                                <label class="block text-xs font-medium text-muted mb-1.5">Target Database</label>
                                <x-ui.select wire:model="restoreTargetDb" class="font-mono">
                                    <option value="">-- Choose Database --</option>
                                    @foreach ($databases as $d)
                                        <option value="{{ $d->name }}">{{ $d->name }}</option>
                                    @endforeach
                                </x-ui.select>
                                @error('restoreTargetDb') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                            </div>
                        @endif

                        <!-- Single Path Input -->
                        @if ($restoreType === 'single_path')
                            <div>
                                <label class="block text-xs font-medium text-muted mb-1.5">Target File or Folder Path</label>
                                <x-ui.input type="text" wire:model="restoreTargetPath" placeholder="/home/{{ $account->linux_username }}/public_html/wp-content" class="font-mono" />
                                @error('restoreTargetPath') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-border mt-4">
                        <x-ui.button type="button" wire:click="closeRestoreWizard" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            Confirm &amp; Execute Restore
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Delete Confirmation Modal -->
    @if ($backupToDeleteId)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-bold text-main mb-2">Delete Backup Snapshot?</h3>
                <p class="text-xs text-muted mb-6">
                    Are you sure you want to delete this restic snapshot? The snapshot will be permanently purged from the repository.
                </p>
                <div class="flex justify-end gap-3">
                    <x-ui.button type="button" wire:click="cancelDelete" variant="ghost" size="sm">
                        Cancel
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="deleteBackup" variant="danger" size="sm">
                        Confirm Delete
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
