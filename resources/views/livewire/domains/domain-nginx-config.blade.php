<div>
    <x-layout.page-header
        title="Nginx Config: {{ $domain->domain }}"
        subtitle="Inspect the managed virtual host configuration and safely customize the editable Nginx block."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('domains.index') }}" variant="outline" size="sm">
                &larr; Back to Domains
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <x-domain-tabs :domain="$domain" active="nginx" />

    @if ($statusMessage)
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ $statusMessage }}
        </div>
    @endif

    @if ($errorMessage)
        <div class="mb-6 rounded-[20px] bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-600 dark:text-rose-400">
            {{ $errorMessage }}
        </div>
    @endif

    @if ($nginxTestOutput)
        <x-ui.card class="mb-6 p-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-2">nginx -t Output</div>
            <pre class="text-xs font-mono bg-[var(--bg-shell)] p-3 rounded-xl border border-[var(--border)] overflow-x-auto text-[var(--text)]">{{ $nginxTestOutput }}</pre>
        </x-ui.card>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Left: Full Read-Only View with Locked Lines Shaded -->
        <x-ui.card class="p-5">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="text-sm font-semibold text-[var(--text)]">Virtual Host File</h3>
                    <p class="text-xs text-[var(--text-muted)] font-mono">/etc/nginx/sites-available/{{ $domain->domain }}.conf</p>
                </div>
                <x-ui.chip variant="neutral" size="xs">Read-only preview</x-ui.chip>
            </div>

            <div class="rounded-xl border border-[var(--border)] overflow-hidden bg-[var(--bg-shell)] max-h-[540px] overflow-y-auto font-mono text-xs">
                @forelse ($lines as $line)
                    <div @class([
                        'flex items-start px-3 py-0.5 border-b border-[var(--border)]/30',
                        'bg-slate-200/40 dark:bg-slate-800/50 text-[var(--text-muted)]' => $line['locked'],
                        'bg-emerald-500/5 text-[var(--text)]' => ! $line['locked'],
                    ])>
                        <span class="w-8 shrink-0 select-none text-[10px] text-[var(--text-muted)] pt-0.5">{{ $line['line'] }}</span>
                        <span class="w-5 shrink-0 select-none pt-0.5">
                            @if ($line['locked'])
                                <svg class="w-3.5 h-3.5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" title="Locked managed directive">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            @else
                                <span class="text-emerald-500 font-bold">›</span>
                            @endif
                        </span>
                        <pre class="whitespace-pre-wrap break-all flex-1">{{ $line['text'] }}</pre>
                    </div>
                @empty
                    <div class="p-6 text-center text-[var(--text-muted)]">
                        No virtual host file found on the server yet.
                    </div>
                @endforelse
            </div>
        </x-ui.card>

        <!-- Right: Editable Custom Block + Controls -->
        <div class="space-y-6">
            <x-ui.card class="p-5">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="text-sm font-semibold text-[var(--text)]">
                            {{ $advancedMode ? 'Full Vhost Editor (Advanced Mode)' : 'Editable Custom Block' }}
                        </h3>
                        <p class="text-xs text-[var(--text-muted)]">
                            {{ $advancedMode ? 'Editing full vhost file. Cross-account paths and panel vhosts remain blocked.' : 'Only allowed directives (location, try_files, rewrite, return, add_header, gzip, etc.) are permitted.' }}
                        </p>
                    </div>

                    @if (auth()->user()->isAdmin())
                        <label class="inline-flex items-center gap-2 text-xs font-medium text-[var(--text)] cursor-pointer">
                            <input type="checkbox" wire:model.live="advancedMode" class="rounded border-[var(--border)]">
                            <span>Advanced mode</span>
                        </label>
                    @endif
                </div>

                @if ($advancedMode && auth()->user()->isAdmin())
                    <div class="mb-4 p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-xs">
                        <p class="font-semibold text-amber-600 dark:text-amber-400 mb-2">
                            Admin Advanced Mode: Type <code class="font-mono">{{ $domain->domain }}</code> to confirm editing managed directives.
                        </p>
                        <x-ui.input
                            name="advancedConfirmDomain"
                            wire:model.live="advancedConfirmDomain"
                            placeholder="{{ $domain->domain }}"
                        />
                    </div>
                @endif

                <div class="relative rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] overflow-hidden">
                    <textarea
                        wire:model="{{ $advancedMode && auth()->user()->isAdmin() ? 'fullContent' : 'customBlock' }}"
                        rows="16"
                        spellcheck="false"
                        class="w-full p-3 font-mono text-xs bg-transparent text-[var(--text)] focus:outline-none resize-y"
                        placeholder="# Example:&#10;location /api/ {&#10;    try_files $uri $uri/ /index.php?$query_string;&#10;}"
                    ></textarea>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <x-ui.button variant="primary" size="sm" wire:click="openSaveModal">
                            Save &amp; test
                        </x-ui.button>
                        <x-ui.button
                            variant="outline"
                            size="sm"
                            wire:click="resetToDefault"
                            wire:confirm="Reset this domain's Nginx configuration to the default template? A backup will be saved first."
                        >
                            Reset to default
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>

            <!-- Backup Restore Card -->
            <x-ui.card class="p-5">
                <h3 class="text-sm font-semibold text-[var(--text)] mb-2">Config Backups (Last 20)</h3>
                @if (! empty($backups))
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                        <div class="flex-1">
                            <x-ui.select name="selectedBackupId" wire:model="selectedBackupId">
                                @foreach ($backups as $b)
                                    <option value="{{ $b }}">{{ $b }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                        <x-ui.button
                            variant="secondary"
                            size="sm"
                            wire:click="restoreBackup"
                            wire:confirm="Restore the selected backup and reload Nginx?"
                        >
                            Restore backup
                        </x-ui.button>
                    </div>
                @else
                    <p class="text-xs text-[var(--text-muted)]">No config backups recorded yet. Backups are created automatically on every save or reset.</p>
                @endif
            </x-ui.card>
        </div>
    </div>

    <!-- Confirmation Modal before Saving -->
    @if ($showSaveModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <x-ui.card class="max-w-md w-full p-6">
                <h3 class="text-base font-semibold text-[var(--text)]">Confirm Nginx Configuration Change</h3>
                <p class="mt-2 text-xs text-[var(--text-muted)]">
                    This will back up the current configuration for <strong>{{ $domain->domain }}</strong>, validate the new configuration with <code class="font-mono">nginx -t</code>, and reload Nginx if valid. If validation fails, the previous configuration will be restored automatically.
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <x-ui.button variant="outline" size="sm" wire:click="closeSaveModal">Cancel</x-ui.button>
                    <x-ui.button variant="primary" size="sm" wire:click="saveConfig">Confirm &amp; Save</x-ui.button>
                </div>
            </x-ui.card>
        </div>
    @endif
</div>
