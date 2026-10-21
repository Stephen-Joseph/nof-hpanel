<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('domains.index') }}" class="text-xs text-indigo-400 hover:text-indigo-300 transition">&larr; Back to Domains</a>
            </div>
            <h2 class="text-2xl font-bold text-white tracking-tight">PHP Manager: {{ $domain->domain }}</h2>
            <p class="text-sm text-slate-400 mt-1">Configure per-domain PHP versions, isolated PHP-FPM worker pools, and safe runtime php.ini directives.</p>
        </div>
    </div>

    @if (session()->has('status'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: Version & Worker Pool Status -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Version & Pool Info Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <h3 class="text-base font-semibold text-white mb-4">Environment &amp; Isolation</h3>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Active PHP Version</label>
                        <select wire:model="php_version"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                            @foreach ($allowedVersions as $ver)
                                <option value="{{ $ver }}">PHP {{ $ver }} (Ondřej Surý PPA)</option>
                            @endforeach
                        </select>
                        @error('php_version') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="pt-2 border-t border-slate-800 space-y-2.5">
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-400">Account Linux User:</span>
                            <span class="font-mono text-white">{{ $domain->account->linux_username }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-400">Package Workers (pm.max_children):</span>
                            <span class="font-semibold text-emerald-400">{{ $packageWorkers }} workers</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-400">Max Memory Quota:</span>
                            <span class="font-semibold text-indigo-400">{{ $packageMaxMemory }} MB</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-400">Process Manager Mode:</span>
                            <span class="font-mono text-slate-300">ondemand</span>
                        </div>
                    </div>

                    <div class="p-3 bg-slate-800/40 border border-slate-800 rounded-lg text-[11px] text-slate-400 space-y-1">
                        <span class="font-semibold text-slate-300">FPM Unix Socket:</span>
                        <div class="font-mono text-slate-400 break-all">/run/php/php{{ $php_version }}-fpm-{{ $domain->account->linux_username }}.sock</div>
                    </div>
                </div>
            </div>

            <!-- FPM Worker Status Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-white">FPM Worker Status</h3>
                    <button wire:click="checkFpmStatus" wire:loading.attr="disabled"
                        class="text-xs text-indigo-400 hover:text-indigo-300 font-medium transition flex items-center gap-1">
                        <span wire:loading.remove wire:target="checkFpmStatus">Refresh</span>
                        <span wire:loading wire:target="checkFpmStatus">Checking...</span>
                    </button>
                </div>

                @if ($hasLoadedStatus && $fpmStatus)
                    @if (isset($fpmStatus['error']))
                        <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-xs text-rose-300">
                            {{ $fpmStatus['error'] }}
                        </div>
                    @else
                        <div class="space-y-3">
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-slate-400">Active Workers:</span>
                                <span class="font-semibold text-emerald-400">{{ $fpmStatus['active_workers'] ?? 0 }}</span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-slate-400">Idle Workers:</span>
                                <span class="font-mono text-slate-300">{{ $fpmStatus['idle_workers'] ?? 0 }}</span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-slate-400">Total Pool Workers:</span>
                                <span class="font-semibold text-white">{{ $fpmStatus['total_workers'] ?? 0 }} / {{ $packageWorkers }}</span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-slate-400">Socket Available:</span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium {{ ($fpmStatus['socket_exists'] ?? false) ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400' }}">
                                    {{ ($fpmStatus['socket_exists'] ?? false) ? 'Ready' : 'Not Found' }}
                                </span>
                            </div>
                        </div>
                    @endif
                @else
                    <div class="text-center py-4 text-xs text-slate-500">
                        Click "Refresh" to inspect live pool workers executing for this account.
                    </div>
                @endif
            </div>
        </div>

        <!-- Right Column: Safe php.ini Overrides Form -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <div class="mb-4">
                    <h3 class="text-base font-semibold text-white">PHP Runtime Directives (php.ini)</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Customize safe execution limits and error behavior for this website. Changes are validated with <span class="font-mono text-slate-300">php-fpm -t</span> before activation.</p>
                </div>

                <form wire:submit.prevent="saveSettings" class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- memory_limit -->
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Memory Limit (memory_limit)</label>
                            <select wire:model="memory_limit" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                                <option value="64M">64 MB</option>
                                <option value="128M">128 MB</option>
                                <option value="256M">256 MB</option>
                                <option value="512M">512 MB</option>
                                <option value="1024M">1024 MB (1 GB)</option>
                            </select>
                            @error('memory_limit') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                            <p class="text-[11px] text-slate-500 mt-1">Maximum RAM allocated to a single PHP script execution.</p>
                        </div>

                        <!-- max_execution_time -->
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Max Execution Time (seconds)</label>
                            <select wire:model="max_execution_time" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                                <option value="30">30 seconds (Default)</option>
                                <option value="60">60 seconds</option>
                                <option value="120">120 seconds (2 minutes)</option>
                                <option value="300">300 seconds (5 minutes)</option>
                            </select>
                            @error('max_execution_time') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                            <p class="text-[11px] text-slate-500 mt-1">Timeout for long-running PHP requests.</p>
                        </div>

                        <!-- upload_max_filesize -->
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Upload Max Filesize</label>
                            <select wire:model="upload_max_filesize" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                                <option value="10M">10 MB</option>
                                <option value="32M">32 MB</option>
                                <option value="64M">64 MB</option>
                                <option value="128M">128 MB</option>
                                <option value="256M">256 MB</option>
                            </select>
                            @error('upload_max_filesize') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <!-- post_max_size -->
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">POST Max Size (post_max_size)</label>
                            <select wire:model="post_max_size" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                                <option value="10M">10 MB</option>
                                <option value="32M">32 MB</option>
                                <option value="64M">64 MB</option>
                                <option value="128M">128 MB</option>
                                <option value="256M">256 MB</option>
                            </select>
                            @error('post_max_size') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <!-- display_errors -->
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-slate-300 mb-1">Display Errors (display_errors)</label>
                            <select wire:model="display_errors" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                                <option value="off">Off (Recommended for production - logs to error_log)</option>
                                <option value="on">On (Development and debugging only)</option>
                            </select>
                            @error('display_errors') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                            <p class="text-[11px] text-slate-500 mt-1">Leaving display_errors on in production can leak database credentials and stack traces.</p>
                        </div>
                    </div>

                    <div class="flex justify-end pt-3">
                        <button type="submit"
                            class="py-2 px-5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm focus:outline-none transition">
                            Apply PHP &amp; Pool Configuration
                        </button>
                    </div>
                </form>
            </div>

            <!-- Security & Log Information -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <h4 class="text-sm font-semibold text-white mb-2">Isolation &amp; Diagnostics</h4>
                <ul class="text-xs text-slate-400 space-y-1.5 list-disc list-inside">
                    <li><strong>Open Basedir:</strong> PHP execution is strictly isolated to <span class="font-mono text-slate-300">/home/{{ $domain->account->linux_username }}/</span>, protecting against cross-account file inspection.</li>
                    <li><strong>Error Log:</strong> All uncaught PHP fatal errors and notices are logged to <span class="font-mono text-slate-300">/home/{{ $domain->account->linux_username }}/logs/php_error.log</span>.</li>
                    <li><strong>Worker Lifecycle:</strong> Under <span class="font-mono text-slate-300">ondemand</span> process management, workers spin down after 10 seconds of inactivity to conserve server RAM.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

