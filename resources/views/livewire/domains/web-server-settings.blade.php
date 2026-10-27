<div class="space-y-6">
    <!-- Breadcrumb & Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-[var(--text-muted)] mb-1">
                <a href="{{ route('domains.index') }}" wire:navigate class="hover:text-[var(--text)] transition">Domains</a>
                <span>&rsaquo;</span>
                <span class="font-mono text-[var(--text)]">{{ $domain->domain }}</span>
                <span>&rsaquo;</span>
                <span>Web Server</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-[var(--text)]">Web Server Settings</h1>
            <p class="text-sm text-[var(--text-muted)] mt-1">
                Configure structured Nginx directives and custom routing for <span class="font-mono font-medium text-[var(--text)]">{{ $domain->domain }}</span>.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <x-ui.button variant="outline" size="sm" onclick="window.location='{{ route('domains.index') }}'">
                Back to Domains
            </x-ui.button>
        </div>
    </div>

    <!-- Alerts -->
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

    @if ($nginxTestOutput)
        <div class="rounded-2xl border border-rose-500/40 bg-rose-950/20 p-4 font-mono text-xs text-rose-300">
            <div class="font-bold uppercase tracking-wider text-[10px] mb-1">Nginx Validation Error:</div>
            <pre class="whitespace-pre-wrap">{{ $nginxTestOutput }}</pre>
        </div>
    @endif

    <form wire:submit.prevent="saveSettings" class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left 2 Cols: Structured Form -->
            <div class="lg:col-span-2 space-y-6">
                <!-- General Directives Card -->
                <x-ui.card>
                    <div class="p-6 space-y-5">
                        <div class="border-b border-[var(--border)] pb-3">
                            <h2 class="text-base font-bold text-[var(--text)]">Directives &amp; Execution</h2>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5">Core HTTP request limits and performance options</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Max Upload Body Size -->
                            <div>
                                <label class="block text-xs font-semibold text-[var(--text)] mb-1">
                                    Max Upload Body Size (MB)
                                </label>
                                <input
                                    type="number"
                                    min="1"
                                    max="128"
                                    wire:model="max_upload_body_size_mb"
                                    class="w-full text-xs rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] px-3 py-2 text-[var(--text)] focus:ring-2 focus:ring-blue-500"
                                />
                                <p class="text-[11px] text-[var(--text-muted)] mt-1">Sets <code class="font-mono">client_max_body_size</code> (1 - 128 MB).</p>
                                @error('max_upload_body_size_mb')
                                    <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            <!-- Static Asset Cache -->
                            <div>
                                <label class="block text-xs font-semibold text-[var(--text)] mb-1">
                                    Static Assets Caching
                                </label>
                                <select
                                    wire:model="cache_lifetime_static"
                                    class="w-full text-xs rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] px-3 py-2 text-[var(--text)] focus:ring-2 focus:ring-blue-500"
                                >
                                    <option value="none">Disabled (No Cache-Control)</option>
                                    <option value="1d">1 Day (expires 1d)</option>
                                    <option value="7d">7 Days (expires 7d)</option>
                                    <option value="30d">30 Days (expires 30d)</option>
                                </select>
                                <p class="text-[11px] text-[var(--text-muted)] mt-1">Applies to css, js, png, jpg, gif, svg, woff2.</p>
                                @error('cache_lifetime_static')
                                    <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                            <!-- Clean URLs -->
                            <div class="flex items-start gap-3 p-3.5 rounded-2xl border border-[var(--border)] bg-[var(--bg-shell)]/50">
                                <input
                                    type="checkbox"
                                    id="clean_urls"
                                    wire:model="clean_urls"
                                    class="mt-1 rounded text-blue-600 focus:ring-blue-500"
                                />
                                <label for="clean_urls" class="text-xs cursor-pointer">
                                    <span class="font-semibold text-[var(--text)] block">Clean URLs</span>
                                    <span class="text-[var(--text-muted)] block mt-0.5">
                                        Uses profile routing fallback (e.g. <code class="font-mono text-[10px]">.php</code> script routing).
                                    </span>
                                </label>
                            </div>

                            <!-- Force HTTPS -->
                            <div class="flex items-start gap-3 p-3.5 rounded-2xl border border-[var(--border)] bg-[var(--bg-shell)]/50">
                                <input
                                    type="checkbox"
                                    id="force_https"
                                    wire:model="force_https"
                                    class="mt-1 rounded text-blue-600 focus:ring-blue-500"
                                />
                                <label for="force_https" class="text-xs cursor-pointer">
                                    <span class="font-semibold text-[var(--text)] block">Force HTTPS</span>
                                    <span class="text-[var(--text-muted)] block mt-0.5">
                                        Redirects all non-TLS requests to HTTPS with HTTP 301.
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Default Index Files -->
                        <div class="pt-2">
                            <label class="block text-xs font-semibold text-[var(--text)] mb-1.5">
                                Default Index Files
                            </label>
                            <div class="flex flex-wrap gap-4 text-xs">
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model="default_index" value="index.php" class="rounded text-blue-600" />
                                    <span class="font-mono text-[var(--text)]">index.php</span>
                                </label>
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model="default_index" value="index.html" class="rounded text-blue-600" />
                                    <span class="font-mono text-[var(--text)]">index.html</span>
                                </label>
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model="default_index" value="index.htm" class="rounded text-blue-600" />
                                    <span class="font-mono text-[var(--text)]">index.htm</span>
                                </label>
                            </div>
                            @error('default_index')
                                <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                </x-ui.card>

                <!-- Custom Error Pages Card -->
                <x-ui.card>
                    <div class="p-6 space-y-4">
                        <div class="border-b border-[var(--border)] pb-3">
                            <h2 class="text-base font-bold text-[var(--text)]">Custom Error Pages</h2>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5">Map HTTP status codes to documents inside your <code class="font-mono">public_html</code></p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-[var(--text)] mb-1">
                                    404 Not Found Page
                                </label>
                                <input
                                    type="text"
                                    wire:model="custom_error_404"
                                    placeholder="/404.html"
                                    class="w-full text-xs font-mono rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] px-3 py-2 text-[var(--text)] focus:ring-2 focus:ring-blue-500"
                                />
                                @error('custom_error_404')
                                    <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-[var(--text)] mb-1">
                                    403 Forbidden Page
                                </label>
                                <input
                                    type="text"
                                    wire:model="custom_error_403"
                                    placeholder="/403.html"
                                    class="w-full text-xs font-mono rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] px-3 py-2 text-[var(--text)] focus:ring-2 focus:ring-blue-500"
                                />
                                @error('custom_error_403')
                                    <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>
                    </div>
                </x-ui.card>

                <!-- Custom Redirects Card -->
                <x-ui.card>
                    <div class="p-6 space-y-4">
                        <div class="flex justify-between items-center border-b border-[var(--border)] pb-3">
                            <div>
                                <h2 class="text-base font-bold text-[var(--text)]">Custom URL Redirects</h2>
                                <p class="text-xs text-[var(--text-muted)] mt-0.5">Max 20 rules. Target must be http/https or relative path without loops.</p>
                            </div>
                            <x-ui.button type="button" variant="secondary" size="xs" wire:click="addRedirect">
                                + Add Redirect
                            </x-ui.button>
                        </div>

                        <div class="space-y-3">
                            @forelse ($redirects as $index => $r)
                                <div class="flex flex-col sm:flex-row items-center gap-2 p-3 rounded-xl border border-[var(--border)] bg-[var(--bg-shell)]/40">
                                    <div class="w-full sm:w-1/3">
                                        <label class="block text-[10px] uppercase font-bold text-[var(--text-muted)] mb-1">Source Path</label>
                                        <input
                                            type="text"
                                            wire:model="redirects.{{ $index }}.source"
                                            placeholder="/old-path"
                                            class="w-full text-xs font-mono rounded-lg border border-[var(--border)] bg-[var(--bg-card)] px-2.5 py-1.5 text-[var(--text)]"
                                        />
                                    </div>

                                    <div class="w-full sm:w-1/2">
                                        <label class="block text-[10px] uppercase font-bold text-[var(--text-muted)] mb-1">Target URL</label>
                                        <input
                                            type="text"
                                            wire:model="redirects.{{ $index }}.target"
                                            placeholder="https://example.com/new"
                                            class="w-full text-xs font-mono rounded-lg border border-[var(--border)] bg-[var(--bg-card)] px-2.5 py-1.5 text-[var(--text)]"
                                        />
                                    </div>

                                    <div class="w-full sm:w-28">
                                        <label class="block text-[10px] uppercase font-bold text-[var(--text-muted)] mb-1">Code</label>
                                        <select
                                            wire:model="redirects.{{ $index }}.code"
                                            class="w-full text-xs rounded-lg border border-[var(--border)] bg-[var(--bg-card)] px-2 py-1.5 text-[var(--text)]"
                                        >
                                            <option value="301">301 Perm</option>
                                            <option value="302">302 Temp</option>
                                        </select>
                                    </div>

                                    <div class="pt-5 shrink-0">
                                        <x-ui.button type="button" variant="ghost" size="xs" wire:click="removeRedirect({{ $index }})" title="Remove">
                                            &times;
                                        </x-ui.button>
                                    </div>
                                </div>
                                @error("redirects.{$index}.source")
                                    <span class="text-rose-500 text-xs block">{{ $message }}</span>
                                @enderror
                                @error("redirects.{$index}.target")
                                    <span class="text-rose-500 text-xs block">{{ $message }}</span>
                                @enderror
                            @empty
                                <p class="text-xs text-center text-[var(--text-muted)] py-4">No custom redirect rules configured.</p>
                            @endforelse
                        </div>
                    </div>
                </x-ui.card>

                <!-- Submit Button -->
                <div class="flex justify-end gap-3">
                    <x-ui.button type="submit" variant="primary" size="md" loading="saveSettings">
                        Save &amp; Deploy Settings
                    </x-ui.button>
                </div>
            </div>

            <!-- Right Col: Generated Nginx Include Preview -->
            <div class="space-y-6">
                <x-ui.card>
                    <div class="p-6 space-y-3">
                        <div class="border-b border-[var(--border)] pb-2 flex justify-between items-center">
                            <div>
                                <h3 class="text-sm font-bold text-[var(--text)]">Generated Include</h3>
                                <p class="text-[11px] font-mono text-[var(--text-muted)]">/etc/nginx/hpanel.d/{{ $domain->domain }}.custom.conf</p>
                            </div>
                            @if ($customConfigExists)
                                <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                    Active
                                </span>
                            @else
                                <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-slate-500/10 text-slate-400 border border-slate-500/20">
                                    Default
                                </span>
                            @endif
                        </div>

                        <p class="text-xs text-[var(--text-muted)]">
                            This file is rendered by the server agent from your structured settings. Direct manual modifications are disallowed to prevent security vulnerabilities.
                        </p>

                        <div class="rounded-xl border border-[var(--border)] bg-slate-950 p-3 overflow-x-auto text-slate-200 font-mono text-[11px] max-h-96">
                            @if ($generatedCustomConfig)
                                <pre class="whitespace-pre-wrap leading-relaxed">{{ $generatedCustomConfig }}</pre>
                            @else
                                <span class="text-slate-500 italic">No custom configuration generated yet.</span>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            </div>
        </div>
    </form>
</div>
