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

    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
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

            <!-- Security & Log Information -->
            <x-ui.card>
                <h4 class="text-sm font-semibold text-main mb-2">Isolation &amp; Diagnostics</h4>
                <ul class="text-xs text-muted space-y-1.5 list-disc list-inside">
                    <li><strong>Open Basedir:</strong> PHP execution is strictly isolated to <span class="font-mono text-main">/home/{{ $domain->account->linux_username }}/</span>, protecting against cross-account file inspection.</li>
                    <li><strong>Error Log:</strong> All uncaught PHP fatal errors and notices are logged to <span class="font-mono text-main">/home/{{ $domain->account->linux_username }}/logs/php_error.log</span>.</li>
                    <li><strong>Worker Lifecycle:</strong> Under <span class="font-mono text-main">ondemand</span> process management, workers spin down after 10 seconds of inactivity to conserve server RAM.</li>
                </ul>
            </x-ui.card>
        </div>
    </div>
</div>
