<div>
    <x-layout.page-header
        title="Domains &amp; Websites"
        subtitle="Manage primary, addon, and subdomain websites, document roots, and web server configurations."
    >
        <x-slot:actions>
            <x-ui.button variant="primary" onclick="window.location='{{ route('domains.create') }}'">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>Add Domain</span>
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    @if (session()->has('status'))
        <div class="mb-6">
            <x-ui.chip variant="success" size="lg" class="w-full justify-start py-2.5 px-4 rounded-2xl">
                {{ session('status') }}
            </x-ui.chip>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="mb-6">
            <x-ui.chip variant="danger" size="lg" class="w-full justify-start py-2.5 px-4 rounded-2xl">
                {{ session('error') }}
            </x-ui.chip>
        </div>
    @endif

    <!-- Filters Toolbar (Clean Modern Inline Toolbar) -->
    <div class="mb-6 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
        <div class="flex-1 max-w-md">
            <x-ui.input
                name="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Filter by domain name..."
                prefix-icon="search"
            />
        </div>

        <div class="w-full sm:w-48">
            <x-ui.select name="typeFilter" wire:model.live="typeFilter">
                <option value="">All Types</option>
                <option value="primary">Primary</option>
                <option value="addon">Addon</option>
                <option value="subdomain">Subdomain</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Domains Table -->
    <x-ui.table :headers="['Domain', 'Account / User', 'Type', 'Profile', 'Document Root', 'Actions']">
        @forelse ($domains as $d)
            <tr class="hover:bg-[var(--bg-shell)]/60 transition-colors">
                <td class="px-6 py-4 whitespace-nowrap font-medium text-[var(--text)]">
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <a href="http://{{ $d->domain }}" target="_blank" rel="noopener" class="hover:text-blue-600 dark:hover:text-blue-400 font-semibold flex items-center gap-1">
                            {{ $d->domain }}
                            <svg class="w-3.5 h-3.5 text-[var(--text-muted)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                        </a>
                        @if($d->verification_status === 'verified')
                            <a href="{{ route('domains.dns', $d) }}" title="DNS Verified">
                                <x-ui.chip variant="success" size="xs" dot>Verified</x-ui.chip>
                            </a>
                        @elseif($d->verification_status === 'failed')
                            <a href="{{ route('domains.dns', $d) }}" title="DNS Failed">
                                <x-ui.chip variant="danger" size="xs" dot>DNS Failed</x-ui.chip>
                            </a>
                        @else
                            <a href="{{ route('domains.dns', $d) }}" title="DNS Pending Verification">
                                <x-ui.chip variant="warning" size="xs" dot>Pending DNS</x-ui.chip>
                            </a>
                        @endif

                        @if($d->ssl_status === 'active')
                            <x-ui.chip variant="success" size="xs">SSL</x-ui.chip>
                        @elseif($d->ssl_status === 'pending')
                            <x-ui.chip variant="warning" size="xs">SSL...</x-ui.chip>
                        @elseif($d->force_https)
                            <x-ui.chip variant="success" size="xs" dot>HTTPS</x-ui.chip>
                        @endif
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-[var(--text-muted)]">
                    {{ $d->account->linux_username }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    @php
                        $chipVariant = match($d->type->value) {
                            'primary' => 'primary',
                            'addon' => 'success',
                            'subdomain' => 'warning',
                            default => 'neutral',
                        };
                    @endphp
                    <x-ui.chip :variant="$chipVariant" size="sm">
                        {{ $d->type->label() }}
                    </x-ui.chip>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-xs">
                    <div class="flex items-center gap-2">
                        <select
                            wire:change="changeAppProfile({{ $d->id }}, $event.target.value)"
                            class="text-xs rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] text-[var(--text)] px-2.5 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-sans cursor-pointer"
                            title="Change Application Profile"
                        >
                            <option value="php-generic" {{ $d->app_profile->value === 'php-generic' ? 'selected' : '' }}>php-generic</option>
                            <option value="laravel" {{ $d->app_profile->value === 'laravel' ? 'selected' : '' }}>laravel</option>
                            <option value="wordpress" {{ $d->app_profile->value === 'wordpress' ? 'selected' : '' }}>wordpress</option>
                            <option value="static-spa" {{ $d->app_profile->value === 'static-spa' ? 'selected' : '' }}>static-spa</option>
                        </select>
                        <span class="text-[var(--text-muted)] font-mono text-[11px]">(PHP {{ $d->php_version }})</span>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-[var(--text-muted)] truncate max-w-xs">
                    {{ $d->document_root }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                    <div class="flex items-center justify-end gap-1.5">
                        <x-ui.button variant="secondary" size="xs" wire:click="openProfileModal({{ $d->id }})" title="Change App Profile">
                            Profile
                        </x-ui.button>
                        <x-ui.button variant="outline" size="xs" onclick="window.location='{{ route('domains.redirects', $d) }}'">
                            Redirects
                        </x-ui.button>
                        <x-ui.button variant="outline" size="xs" onclick="window.location='{{ route('domains.ssl', $d) }}'">
                            SSL
                        </x-ui.button>
                        <x-ui.button variant="outline" size="xs" onclick="window.location='{{ route('domains.php', $d) }}'">
                            PHP
                        </x-ui.button>
                        <x-ui.button variant="outline" size="xs" onclick="window.location='{{ route('domains.web-server', $d) }}'" title="Web Server / Nginx Configuration">
                            Nginx
                        </x-ui.button>
                        <x-ui.button variant="outline" size="xs" onclick="window.location='{{ route('domains.dns', $d) }}'">
                            DNS
                        </x-ui.button>
                        @if($d->type->value !== 'primary')
                            <x-ui.button
                                variant="danger"
                                size="xs"
                                wire:click="deleteDomain({{ $d->id }})"
                                wire:confirm="Are you sure you want to delete this domain and its Nginx virtual host?"
                            >
                                Delete
                            </x-ui.button>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="px-6 py-12 text-center">
                    <x-ui.empty-state
                        title="No domains registered yet"
                        description="Click '+ Add Domain' above to create your first virtual host."
                    />
                </td>
            </tr>
        @endforelse
    </x-ui.table>

    <!-- Change Application Profile Modal -->
    @if ($showingProfileModal && $editingDomain)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="closeProfileModal"></div>

                <div class="relative bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] overflow-hidden shadow-2xl transform transition-all sm:max-w-lg w-full z-10">
                    <form wire:submit.prevent="updateAppProfile">
                        <div class="px-6 py-4.5 border-b border-[var(--border)] flex justify-between items-center">
                            <div>
                                <h3 class="text-base font-bold text-[var(--text)] tracking-tight">Application Profile</h3>
                                <p class="text-xs text-[var(--text-muted)] mt-0.5 font-mono">{{ $editingDomain->domain }}</p>
                            </div>
                            <x-ui.icon-button label="Close" variant="ghost" size="sm" wire:click="closeProfileModal">
                                &times;
                            </x-ui.icon-button>
                        </div>

                        <div class="p-6 space-y-3">
                            <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-2">
                                Select Nginx Routing &amp; Execution Profile
                            </label>

                            <!-- php-generic -->
                            <label class="flex items-start gap-3 p-3.5 rounded-2xl border {{ $selectedProfile === 'php-generic' ? 'border-blue-600 bg-blue-50/20 dark:bg-blue-950/20' : 'border-[var(--border)] bg-[var(--bg-shell)]' }} cursor-pointer hover:border-blue-500/50 transition">
                                <input type="radio" wire:model="selectedProfile" value="php-generic" class="mt-1 text-blue-600">
                                <div>
                                    <div class="text-sm font-semibold text-[var(--text)]">PHP Generic</div>
                                    <p class="text-xs text-[var(--text-muted)] mt-0.5">
                                        Standard PHP website with clean URLs and .php script fallback (<code class="font-mono text-[11px]">try_files $uri $uri/ $uri.php$is_args$args;</code>).
                                    </p>
                                </div>
                            </label>

                            <!-- laravel -->
                            <label class="flex items-start gap-3 p-3.5 rounded-2xl border {{ $selectedProfile === 'laravel' ? 'border-blue-600 bg-blue-50/20 dark:bg-blue-950/20' : 'border-[var(--border)] bg-[var(--bg-shell)]' }} cursor-pointer hover:border-blue-500/50 transition">
                                <input type="radio" wire:model="selectedProfile" value="laravel" class="mt-1 text-blue-600">
                                <div>
                                    <div class="text-sm font-semibold text-[var(--text)]">Laravel Framework</div>
                                    <p class="text-xs text-[var(--text-muted)] mt-0.5">
                                        Routes non-file requests to front controller (<code class="font-mono text-[11px]">try_files $uri $uri/ /index.php?$query_string;</code>) with public root.
                                    </p>
                                </div>
                            </label>

                            <!-- wordpress -->
                            <label class="flex items-start gap-3 p-3.5 rounded-2xl border {{ $selectedProfile === 'wordpress' ? 'border-blue-600 bg-blue-50/20 dark:bg-blue-950/20' : 'border-[var(--border)] bg-[var(--bg-shell)]' }} cursor-pointer hover:border-blue-500/50 transition">
                                <input type="radio" wire:model="selectedProfile" value="wordpress" class="mt-1 text-blue-600">
                                <div>
                                    <div class="text-sm font-semibold text-[var(--text)]">WordPress</div>
                                    <p class="text-xs text-[var(--text-muted)] mt-0.5">
                                        Optimized for WordPress CMS with uploads PHP execution blocking and wp-config protection.
                                    </p>
                                </div>
                            </label>

                            <!-- static-spa -->
                            <label class="flex items-start gap-3 p-3.5 rounded-2xl border {{ $selectedProfile === 'static-spa' ? 'border-blue-600 bg-blue-50/20 dark:bg-blue-950/20' : 'border-[var(--border)] bg-[var(--bg-shell)]' }} cursor-pointer hover:border-blue-500/50 transition">
                                <input type="radio" wire:model="selectedProfile" value="static-spa" class="mt-1 text-blue-600">
                                <div>
                                    <div class="text-sm font-semibold text-[var(--text)]">Static / SPA (Single Page App)</div>
                                    <p class="text-xs text-[var(--text-muted)] mt-0.5">
                                        Static HTML/JS sites with client-side routing fallback (<code class="font-mono text-[11px]">try_files $uri $uri/ /index.html;</code>) and asset caching.
                                    </p>
                                </div>
                            </label>

                            @error('selectedProfile')
                                <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="px-6 py-4 bg-[var(--bg-shell)]/60 border-t border-[var(--border)] flex justify-end gap-3">
                            <x-ui.button variant="outline" size="sm" wire:click="closeProfileModal">
                                Cancel
                            </x-ui.button>
                            <x-ui.button type="submit" variant="primary" size="sm" loading="updateAppProfile">
                                Save &amp; Regenerate Virtual Host
                            </x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
