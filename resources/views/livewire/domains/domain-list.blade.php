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
                    <div class="flex items-center gap-1.5">
                        <a href="http://{{ $d->domain }}" target="_blank" rel="noopener" class="hover:text-blue-600 dark:hover:text-blue-400 font-semibold flex items-center gap-1">
                            {{ $d->domain }}
                            <svg class="w-3.5 h-3.5 text-[var(--text-muted)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                        </a>
                        @if($d->force_https)
                            <x-ui.chip variant="success" size="sm" dot>HTTPS</x-ui.chip>
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
                <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-[var(--text-muted)]">
                    {{ $d->app_profile->label() }} <span class="text-[var(--text)] font-semibold">(PHP {{ $d->php_version }})</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-[var(--text-muted)] truncate max-w-xs">
                    {{ $d->document_root }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                    <div class="flex items-center justify-end gap-1.5">
                        <x-ui.button variant="outline" size="xs" onclick="window.location='{{ route('domains.redirects', $d) }}'">
                            Redirects
                        </x-ui.button>
                        <x-ui.button variant="outline" size="xs" onclick="window.location='{{ route('domains.ssl', $d) }}'">
                            SSL
                        </x-ui.button>
                        <x-ui.button variant="outline" size="xs" onclick="window.location='{{ route('domains.php', $d) }}'">
                            PHP
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
</div>
