<div>
    <x-layout.page-header 
        title="PHP Manager: {{ $domain->domain }}" 
        subtitle="Configure per-domain PHP versions, isolated PHP-FPM worker pools, and safe runtime php.ini directives."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('domains.index') }}" variant="outline" size="sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Domains
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <x-domain-tabs :domain="$domain" active="php" />

    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    @if ($actionOutput)
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            <pre class="whitespace-pre-wrap font-mono text-xs">{{ $actionOutput }}</pre>
        </div>
    @endif

    @if ($actionError)
        <div class="mb-6 rounded-[20px] bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-600 dark:text-rose-400">
            <pre class="whitespace-pre-wrap font-mono text-xs">{{ $actionError }}</pre>
        </div>
    @endif

    @if ($laravelMinPhpVersion !== '' && version_compare($php_version, $laravelMinPhpVersion, '<'))
        <div class="mb-6 rounded-[20px] bg-amber-500/10 border border-amber-500/30 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="text-sm text-amber-700 dark:text-amber-300">
                <span class="font-semibold">PHP Version Warning:</span>
                This Laravel project requires PHP <code class="font-mono font-bold">{{ $laravelMinPhpVersion }}</code> or higher (detected from Composer), but <code class="font-mono font-bold">{{ $php_version }}</code> is currently selected.
            </div>
            <x-ui.button variant="primary" size="sm" wire:click="switchToRequiredVersion">
                Switch to {{ $laravelMinPhpVersion }}
            </x-ui.button>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: Version & Worker Pool Status -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Version & Pool Info Card -->
            <x-ui.card>
                <h3 class="text-base font-semibold text-main mb-4">Environment &amp; Isolation</h3>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Application Profile</label>
                        <x-ui.select wire:model="app_profile">
                            <option value="php-generic">PHP Generic (try_files $uri $uri/ $uri.php$is_args$args;)</option>
                            <option value="laravel">Laravel Framework (try_files $uri $uri/ /index.php?$query_string;)</option>
                            <option value="wordpress">WordPress (try_files $uri $uri/ /index.php?$args;)</option>
                            <option value="static-spa">Static HTML / Single Page App (try_files $uri $uri/ /index.html;)</option>
                        </x-ui.select>
                        <p class="text-[11px] text-muted mt-1">Defines Nginx virtual host URL rewrite &amp; execution profile.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Active PHP Version</label>
                        <x-ui.select wire:model="php_version">
                            @foreach ($allowedVersions as $ver)
                                <option value="{{ $ver }}">PHP {{ $ver }} (Ondřej Surý PPA)</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="pt-3 border-t border-border space-y-2.5">
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-muted">Account Linux User:</span>
                            <span class="font-mono font-medium text-main">{{ $domain->account->linux_username }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-muted">Package Workers (pm.max_children):</span>
                            <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $packageWorkers }} workers</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-muted">Max Memory Quota:</span>
                            <span class="font-semibold text-primary-500">{{ $packageMaxMemory }} MB</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-muted">Process Manager Mode:</span>
                            <span class="font-mono text-muted">ondemand</span>
                        </div>
                    </div>

                    <div class="p-3 bg-shell border border-border rounded-[14px] text-[11px] text-muted space-y-1">
                        <span class="font-semibold text-main">FPM Unix Socket:</span>
                        <div class="font-mono text-muted break-all">/run/php/php{{ $php_version }}-fpm-{{ $domain->account->linux_username }}.sock</div>
                    </div>
                </div>
            </x-ui.card>

            <!-- FPM Worker Status Card -->
            <x-ui.card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-main">FPM Worker Status</h3>
                    <button wire:click="checkFpmStatus" wire:loading.attr="disabled"
                        class="text-xs text-primary-500 hover:text-primary-600 font-medium transition flex items-center gap-1">
                        <span wire:loading.remove wire:target="checkFpmStatus">Refresh</span>
                        <span wire:loading wire:target="checkFpmStatus">Checking...</span>
                    </button>
                </div>

                @if ($hasLoadedStatus && $fpmStatus)
                    @if (isset($fpmStatus['error']))
                        <div class="p-3 rounded-[14px] bg-rose-500/10 border border-rose-500/20 text-xs text-rose-500 dark:text-rose-400">
                            {{ $fpmStatus['error'] }}
                        </div>
                    @else
                        <div class="space-y-3">
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-muted">Active Workers:</span>
                                <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $fpmStatus['active_workers'] ?? 0 }}</span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-muted">Idle Workers:</span>
                                <span class="font-mono text-muted">{{ $fpmStatus['idle_workers'] ?? 0 }}</span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-muted">Total Pool Workers:</span>
                                <span class="font-semibold text-main">{{ $fpmStatus['total_workers'] ?? 0 }} / {{ $packageWorkers }}</span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-muted">Socket Available:</span>
                                <x-ui.chip :variant="($fpmStatus['socket_exists'] ?? false) ? 'success' : 'danger'" size="sm">
                                    {{ ($fpmStatus['socket_exists'] ?? false) ? 'Ready' : 'Not Found' }}
                                </x-ui.chip>
                            </div>
                        </div>
                    @endif
                @else
                    <div class="text-center py-4 text-xs text-muted">
                        Click "Refresh" to inspect live pool workers executing for this account.
                    </div>
                @endif
            </x-ui.card>
        </div>

        <!-- Right Column: Safe php.ini Overrides Form -->
        <div class="lg:col-span-2 space-y-6">
            <x-ui.card>
                <div class="mb-5">
                    <h3 class="text-base font-semibold text-main">PHP Runtime Directives (php.ini)</h3>
                    <p class="text-xs text-muted mt-0.5">Customize safe execution limits and error behavior for this website. Changes are validated with <span class="font-mono font-medium text-main">php-fpm -t</span> before activation.</p>
                </div>

                <form wire:submit.prevent="saveSettings" class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- memory_limit -->
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Memory Limit (memory_limit)</label>
                            <x-ui.select wire:model="memory_limit">
                                <option value="64M">64 MB</option>
                                <option value="128M">128 MB</option>
                                <option value="256M">256 MB</option>
                                <option value="512M">512 MB</option>
                                <option value="1024M">1024 MB (1 GB)</option>
                            </x-ui.select>
                            <p class="text-[11px] text-muted mt-1">Maximum RAM allocated to a single PHP script execution.</p>
                        </div>

                        <!-- max_execution_time -->
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Max Execution Time (seconds)</label>
                            <x-ui.select wire:model="max_execution_time">
                                <option value="30">30 seconds (Default)</option>
                                <option value="60">60 seconds</option>
                                <option value="120">120 seconds (2 minutes)</option>
                                <option value="300">300 seconds (5 minutes)</option>
                            </x-ui.select>
                            <p class="text-[11px] text-muted mt-1">Timeout for long-running PHP requests.</p>
                        </div>

                        <!-- upload_max_filesize -->
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Upload Max Filesize</label>
                            <x-ui.select wire:model="upload_max_filesize">
                                <option value="10M">10 MB</option>
                                <option value="32M">32 MB</option>
                                <option value="64M">64 MB</option>
                                <option value="128M">128 MB</option>
                                <option value="256M">256 MB</option>
                            </x-ui.select>
                        </div>

                        <!-- post_max_size -->
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">POST Max Size (post_max_size)</label>
                            <x-ui.select wire:model="post_max_size">
                                <option value="10M">10 MB</option>
                                <option value="32M">32 MB</option>
                                <option value="64M">64 MB</option>
                                <option value="128M">128 MB</option>
                                <option value="256M">256 MB</option>
                            </x-ui.select>
                        </div>

                        <!-- display_errors -->
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-muted mb-1.5">Display Errors (display_errors)</label>
                            <x-ui.select wire:model="display_errors">
                                <option value="off">Off (Recommended for production - logs to error_log)</option>
                                <option value="on">On (Development and debugging only)</option>
                            </x-ui.select>
                            <p class="text-[11px] text-muted mt-1">Leaving display_errors on in production can leak database credentials and stack traces.</p>
                        </div>
                    </div>

                    <div class="flex justify-end pt-3">
                        <x-ui.button type="submit" variant="primary">
                            Apply PHP &amp; Pool Configuration
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <!-- Laravel Tools & Diagnostics -->
            <x-ui.card>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <div>
                        <h4 class="text-sm font-semibold text-main">Laravel Tools &amp; Error Logs</h4>
                        <p class="text-xs text-muted">Run safe Laravel maintenance commands and inspect recent PHP/Laravel logs.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button variant="outline" size="xs" wire:click="fixLaravelPermissions">
                            Fix Laravel permissions
                        </x-ui.button>
                        <x-ui.button variant="outline" size="xs" wire:click="clearLaravelCaches">
                            Clear caches
                        </x-ui.button>
                        <x-ui.button variant="outline" size="xs" wire:click="openMigrateModal">
                            Run migrations
                        </x-ui.button>
                        <x-ui.button variant="secondary" size="xs" wire:click="loadErrorLogs">
                            Show PHP error log
                        </x-ui.button>
                    </div>
                </div>

                @if ($latestJob)
                    <div class="mt-4 pt-4 border-t border-border">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-main">Latest PHP Deployment Progress</span>
                            <span class="text-[11px] font-mono text-muted">{{ $latestJob->status->value }}</span>
                        </div>
                        @if ($latestJob->error_message)
                            <div class="mb-3 p-2.5 rounded-xl bg-rose-500/10 border border-rose-500/20 text-xs text-rose-500 font-mono">
                                {{ $latestJob->error_message }}
                            </div>
                        @endif
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @foreach ($latestJob->provisioningSteps as $step)
                                <div class="flex items-center justify-between px-3 py-2 rounded-xl bg-shell border border-border text-xs">
                                    <span class="text-main">{{ $step->label }}</span>
                                    <span @class([
                                        'font-mono text-[11px]',
                                        'text-emerald-500' => $step->status === 'done',
                                        'text-rose-500' => $step->status === 'failed',
                                        'text-amber-500' => $step->status === 'running',
                                        'text-muted' => $step->status === 'pending',
                                    ])>{{ $step->status }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($showingLogs)
                    <div class="mt-4 pt-4 border-t border-border space-y-4">
                        <div>
                            <div class="text-xs font-semibold text-main mb-1">PHP Error Log (Last 100 lines)</div>
                            <pre class="text-[11px] font-mono bg-shell p-3 rounded-xl border border-border max-h-56 overflow-y-auto whitespace-pre-wrap text-main">{{ $phpErrorLog ?: 'Empty' }}</pre>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-main mb-1">Laravel Log (Last 100 lines)</div>
                            <pre class="text-[11px] font-mono bg-shell p-3 rounded-xl border border-border max-h-56 overflow-y-auto whitespace-pre-wrap text-main">{{ $laravelLog ?: 'Empty' }}</pre>
                        </div>
                    </div>
                @endif
            </x-ui.card>

            <!-- Security & Log Information -->
            <x-ui.card>
                <h4 class="text-sm font-semibold text-main mb-2">Isolation &amp; Diagnostics</h4>
                <ul class="text-xs text-muted space-y-1.5 list-disc list-inside">
                    <li><strong>Open Basedir:</strong> PHP execution is strictly isolated to <span class="font-mono text-main">/home/{{ $domain->account->linux_username }}/</span>, protecting against cross-account file inspection.</li>
                    <li><strong>Error Log:</strong> All uncaught PHP fatal errors and notices are logged to <span class="font-mono text-main">/home/{{ $domain->account->linux_username }}/logs/php-error.log</span>.</li>
                    <li><strong>Worker Lifecycle:</strong> Under <span class="font-mono text-main">ondemand</span> process management, workers spin down after 10 seconds of inactivity to conserve server RAM.</li>
                </ul>
            </x-ui.card>
        </div>
    </div>

    @if ($showMigrateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <x-ui.card class="max-w-md w-full p-6">
                <h3 class="text-base font-semibold text-main">Confirm Database Migrations</h3>
                <p class="mt-2 text-xs text-muted">
                    This will execute <code class="font-mono">php{{ $php_version }} artisan migrate --force</code> as <code class="font-mono">{{ $domain->account->linux_username }}</code> on <strong>{{ $domain->domain }}</strong>.
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <x-ui.button variant="outline" size="sm" wire:click="closeMigrateModal">Cancel</x-ui.button>
                    <x-ui.button variant="primary" size="sm" wire:click="runLaravelMigrations">Run Migrations</x-ui.button>
                </div>
            </x-ui.card>
        </div>
    @endif
</div>
