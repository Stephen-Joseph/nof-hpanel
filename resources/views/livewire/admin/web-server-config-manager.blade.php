<div class="space-y-6">
    <!-- Header & Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-[var(--text)]">Web Server Configuration</h1>
            <p class="text-sm text-[var(--text-muted)] mt-1">
                Manage Nginx virtual hosts, test configurations atomically, and review revision backups.
            </p>
        </div>

        <div class="flex items-center gap-3">
            @if(count($servers) > 1)
                <select wire:model.live="serverId" wire:change="loadConfigs" class="text-xs rounded-xl border border-[var(--border)] bg-[var(--bg-card)] px-3 py-2 text-[var(--text)]">
                    @foreach($servers as $s)
                        <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->ip_address }})</option>
                    @endforeach
                </select>
            @endif

            <x-ui.button variant="secondary" size="sm" wire:click="testGlobalConfig" loading="testGlobalConfig" title="Run read-only nginx -t">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Test Config (nginx -t)
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" wire:click="loadConfigs" loading="loadConfigs">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </x-ui.button>
        </div>
    </div>

    <!-- Feedback messages -->
    @if ($statusMessage)
        <div class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span>{{ $statusMessage }}</span>
            </div>
            <button wire:click="$set('statusMessage', null)" class="text-xs opacity-70 hover:opacity-100">&times;</button>
        </div>
    @endif

    @if ($errorMessage)
        <div class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-200 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-rose-600 dark:text-rose-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ $errorMessage }}</span>
            </div>
            <button wire:click="$set('errorMessage', null)" class="text-xs opacity-70 hover:opacity-100">&times;</button>
        </div>
    @endif

    <!-- Global Nginx Test Console (if run from header) -->
    @if ($nginxTestOutput && ! $showingEditorModal)
        <div class="rounded-2xl border p-4 font-mono text-xs overflow-x-auto {{ $nginxTestSuccess ? 'border-emerald-500/40 bg-emerald-950/10 text-emerald-400' : 'border-rose-500/40 bg-rose-950/10 text-rose-400' }}">
            <div class="flex justify-between items-center mb-2 pb-1 border-b border-inherit opacity-80">
                <span class="font-bold uppercase tracking-wider text-[10px]">nginx -t console output</span>
                <button wire:click="$set('nginxTestOutput', '')" class="text-xs">&times; Close</button>
            </div>
            <pre class="whitespace-pre-wrap">{{ $nginxTestOutput }}</pre>
        </div>
    @endif

    <!-- Search & Filters -->
    <div class="flex items-center justify-between gap-4">
        <div class="w-full sm:max-w-xs">
            <input
                type="text"
                wire:model.live="search"
                placeholder="Search config by name or owner..."
                class="w-full text-xs rounded-xl border border-[var(--border)] bg-[var(--bg-card)] px-3 py-2 text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
        </div>
        <div class="text-xs text-[var(--text-muted)]">
            Showing {{ count($filteredConfigs) }} configuration(s)
        </div>
    </div>

    <!-- Configurations Table -->
    <x-ui.table>
        <x-slot:header>
            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">File Name</th>
            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Owner</th>
            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Status</th>
            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Size</th>
            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Last Modified</th>
            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Actions</th>
        </x-slot:header>

        @forelse ($filteredConfigs as $c)
            <tr class="hover:bg-[var(--bg-shell)]/50 transition">
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-xs font-semibold text-[var(--text)]">{{ $c['name'] }}</span>
                        @if ($c['name'] === $panelDomain . '.conf')
                            <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-amber-500/20 text-amber-500 border border-amber-500/30">
                                Panel Vhost
                            </span>
                        @endif
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-0.5 text-xs rounded-md bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text-muted)] font-mono">
                        {{ $c['owner'] ?? 'system' }}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    @if ($c['enabled'])
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Enabled
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-slate-500/10 text-slate-400 border border-slate-500/20">
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> Disabled
                        </span>
                    @endif
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-xs text-[var(--text-muted)] font-mono">
                    {{ number_format(($c['size'] ?? 0) / 1024, 1) }} KB
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-xs text-[var(--text-muted)]">
                    {{ !empty($c['mtime_human']) ? \Illuminate\Support\Carbon::parse($c['mtime_human'])->diffForHumans() : '—' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                    <div class="flex items-center justify-end gap-1.5">
                        <x-ui.button variant="secondary" size="xs" wire:click="openEditor('{{ $c['name'] }}')">
                            Edit
                        </x-ui.button>

                        @if ($c['enabled'])
                            <x-ui.button variant="outline" size="xs" wire:click="initiateDisable('{{ $c['name'] }}')">
                                Disable
                            </x-ui.button>
                        @else
                            <x-ui.button variant="outline" size="xs" wire:click="enableConfig('{{ $c['name'] }}')">
                                Enable
                            </x-ui.button>
                        @endif

                        <x-ui.button variant="ghost" size="xs" wire:click="openRestoreModal('{{ $c['name'] }}')" title="View Backups &amp; Restore">
                            Backups
                        </x-ui.button>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="px-6 py-12 text-center text-[var(--text-muted)] text-sm">
                    No configuration files found.
                </td>
            </tr>
        @endforelse
    </x-ui.table>

    <!-- Editor Modal -->
    @if ($showingEditorModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-xs transition-opacity" wire:click="closeEditor"></div>

                <div class="relative bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] overflow-hidden shadow-2xl transform transition-all w-full max-w-4xl z-10 flex flex-col max-h-[90vh]">
                    <!-- Modal Header -->
                    <div class="px-6 py-4 border-b border-[var(--border)] flex justify-between items-center bg-[var(--bg-shell)]/50">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-base font-bold text-[var(--text)] font-mono">{{ $activeEditingName }}</h3>
                                @if ($activeEditingName === $panelDomain . '.conf')
                                    <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-amber-500/20 text-amber-500 border border-amber-500/30">
                                        Panel Vhost
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5">
                                Changes are tested with <code class="font-mono text-[11px]">nginx -t</code>. Bad syntax is rolled back automatically.
                            </p>
                        </div>
                        <x-ui.icon-button label="Close" variant="ghost" size="sm" wire:click="closeEditor">
                            &times;
                        </x-ui.icon-button>
                    </div>

                    <!-- Modal Body / Editor Area -->
                    <div class="p-6 space-y-4 overflow-y-auto flex-1">
                        <div class="relative rounded-2xl border border-[var(--border)] bg-slate-950 text-slate-100 font-mono text-xs overflow-hidden">
                            <textarea
                                wire:model="editingContent"
                                rows="18"
                                spellcheck="false"
                                class="w-full p-4 bg-transparent text-slate-100 font-mono text-xs resize-y focus:outline-none leading-relaxed"
                            ></textarea>
                        </div>

                        <!-- Live Nginx Test Console inside Editor -->
                        @if ($nginxTestOutput)
                            <div class="rounded-xl border p-3 font-mono text-xs {{ $nginxTestSuccess ? 'border-emerald-500/50 bg-emerald-950/20 text-emerald-300' : 'border-rose-500/50 bg-rose-950/20 text-rose-300' }}">
                                <div class="font-semibold uppercase tracking-wider text-[10px] mb-1">nginx -t syntax check:</div>
                                <pre class="whitespace-pre-wrap">{{ $nginxTestOutput }}</pre>
                            </div>
                        @endif
                    </div>

                    <!-- Modal Footer -->
                    <div class="px-6 py-4 bg-[var(--bg-shell)]/60 border-t border-[var(--border)] flex justify-between items-center">
                        <div class="text-xs text-[var(--text-muted)]">
                            Max size: 256 KB. Atomic temporary file swap with automatic rollback.
                        </div>
                        <div class="flex items-center gap-3">
                            <x-ui.button variant="outline" size="sm" wire:click="closeEditor">
                                Cancel
                            </x-ui.button>
                            <x-ui.button variant="primary" size="sm" wire:click="initiateSave" loading="initiateSave">
                                Save &amp; Test (nginx -t)
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Panel Vhost Edit Confirm Modal -->
    @if ($showingPanelConfirmModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-xs transition-opacity" wire:click="$set('showingPanelConfirmModal', false)"></div>

                <div class="relative bg-[var(--bg-card)] border border-amber-500/40 rounded-[24px] overflow-hidden shadow-2xl p-6 max-w-md w-full z-10 space-y-4">
                    <div class="w-12 h-12 rounded-full bg-amber-500/20 text-amber-500 flex items-center justify-center mx-auto">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>

                    <div class="text-center">
                        <h3 class="text-base font-bold text-[var(--text)]">Editing Panel Vhost</h3>
                        <p class="text-xs text-[var(--text-muted)] mt-1">
                            You are modifying the virtual host for the control panel itself (<code class="font-mono text-amber-400">{{ $panelDomain }}</code>). A breaking change may disconnect you from the web interface.
                        </p>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <x-ui.button variant="outline" size="sm" wire:click="$set('showingPanelConfirmModal', false)">
                            Cancel
                        </x-ui.button>
                        <x-ui.button variant="danger" size="sm" wire:click="confirmPanelSave">
                            Confirm &amp; Deploy
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Typed Disable Modal for Panel Vhost -->
    @if ($showingDisableTypedModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-xs transition-opacity" wire:click="$set('showingDisableTypedModal', false)"></div>

                <div class="relative bg-[var(--bg-card)] border border-rose-500/40 rounded-[24px] overflow-hidden shadow-2xl p-6 max-w-md w-full z-10 space-y-4">
                    <div class="w-12 h-12 rounded-full bg-rose-500/20 text-rose-500 flex items-center justify-center mx-auto">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>

                    <div class="text-center">
                        <h3 class="text-base font-bold text-[var(--text)]">Disable Panel Virtual Host?</h3>
                        <p class="text-xs text-[var(--text-muted)] mt-1">
                            Disabling this virtual host will take HPanel offline immediately! To proceed, type <strong class="font-mono text-rose-400">{{ $panelDomain }}</strong> below:
                        </p>
                    </div>

                    <form wire:submit.prevent="confirmTypedDisable" class="space-y-4">
                        <div>
                            <input
                                type="text"
                                wire:model="typedConfirmDomain"
                                placeholder="{{ $panelDomain }}"
                                class="w-full text-xs font-mono rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] px-3 py-2 text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-rose-500 text-center"
                            />
                            @error('typedConfirmDomain')
                                <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <x-ui.button variant="outline" size="sm" type="button" wire:click="$set('showingDisableTypedModal', false)">
                                Cancel
                            </x-ui.button>
                            <x-ui.button variant="danger" size="sm" type="submit" loading="confirmTypedDisable">
                                Disable Panel Vhost
                            </x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <!-- Restore Backup Modal -->
    @if ($showingRestoreModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-xs transition-opacity" wire:click="closeRestoreModal"></div>

                <div class="relative bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] overflow-hidden shadow-2xl p-6 max-w-lg w-full z-10 space-y-4">
                    <div class="flex justify-between items-center border-b border-[var(--border)] pb-3">
                        <div>
                            <h3 class="text-base font-bold text-[var(--text)]">Revision Backups</h3>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5 font-mono">{{ $restoreFileName }}</p>
                        </div>
                        <x-ui.icon-button label="Close" variant="ghost" size="sm" wire:click="closeRestoreModal">
                            &times;
                        </x-ui.icon-button>
                    </div>

                    <div class="space-y-2 max-h-80 overflow-y-auto">
                        @forelse ($backupsList as $b)
                            <div class="flex items-center justify-between p-3 rounded-xl border border-[var(--border)] bg-[var(--bg-shell)]/50">
                                <div>
                                    <div class="text-xs font-semibold text-[var(--text)] font-mono">{{ $b['id'] }}</div>
                                    <div class="text-[11px] text-[var(--text-muted)] mt-0.5">
                                        {{ \Illuminate\Support\Carbon::parse($b['formatted_time'])->diffForHumans() }}
                                        &middot; {{ number_format($b['size'] / 1024, 1) }} KB
                                    </div>
                                </div>
                                <x-ui.button
                                    variant="secondary"
                                    size="xs"
                                    wire:click="restoreBackup('{{ $b['id'] }}')"
                                    wire:confirm="Are you sure you want to restore {{ $restoreFileName }} to this backup revision?"
                                >
                                    Restore
                                </x-ui.button>
                            </div>
                        @empty
                            <p class="text-xs text-center text-[var(--text-muted)] py-6">No previous backups available for this file.</p>
                        @endforelse
                    </div>

                    <div class="flex justify-end pt-2">
                        <x-ui.button variant="outline" size="sm" wire:click="closeRestoreModal">
                            Close
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
