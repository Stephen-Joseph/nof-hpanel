<div>
    <!-- Header -->
    <x-layout.page-header 
        title="WordPress Manager &middot; {{ $account->primary_domain }}" 
        subtitle="1-Click WordPress installations with automatic MariaDB database provisioning and optimized Nginx caching."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('accounts.show', $account) }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Account
                </x-ui.button>
                <x-ui.button wire:click="openInstallModal" variant="primary" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Install New WordPress
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Feedback messages -->
    @if (session()->has('success'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('success') }}
        </div>
    @endif

    <!-- Installations Grid -->
    <div class="space-y-4">
        @forelse ($account->wordPressInstalls as $wp)
            <x-ui.card>
                <div class="sm:flex sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-3">
                            <h3 class="text-base font-bold text-main">{{ $wp->site_title }}</h3>
                            @if($wp->status === 'installed')
                                <x-ui.chip variant="success" size="sm">Active</x-ui.chip>
                            @elseif($wp->status === 'installing')
                                <x-ui.chip variant="info" size="sm" class="animate-pulse">Installing...</x-ui.chip>
                            @else
                                <x-ui.chip variant="danger" size="sm">Failed</x-ui.chip>
                            @endif
                        </div>
                        <p class="text-xs font-mono text-muted mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span>URL: <a href="https://{{ $wp->domain->domain }}" target="_blank" class="text-primary-500 hover:underline">https://{{ $wp->domain->domain }}</a></span>
                            <span>&bull;</span>
                            <span>Path: <strong class="text-main">{{ $wp->install_path }}</strong></span>
                            <span>&bull;</span>
                            <span>DB: <strong class="text-main">{{ $wp->db_name }}</strong></span>
                            <span>&bull;</span>
                            <span>Admin: <strong class="text-main">{{ $wp->admin_user }}</strong></span>
                        </p>
                    </div>

                    <div class="mt-4 sm:mt-0 flex items-center gap-2">
                        <x-ui.button href="https://{{ $wp->domain->domain }}/wp-admin" target="_blank" variant="outline" size="sm">
                            <span>WP Admin</span>
                            <svg class="w-3.5 h-3.5 ml-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                        </x-ui.button>
                        <button wire:click="confirmUninstall({{ $wp->id }})" class="w-8 h-8 rounded-full border border-border flex items-center justify-center text-muted hover:text-rose-500 hover:border-rose-500/30 transition cursor-pointer" title="Uninstall WordPress">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card class="p-12 text-center">
                <div class="w-12 h-12 rounded-full bg-primary-100 dark:bg-primary-950/40 text-primary-500 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-main">No WordPress Installations Found</h3>
                <p class="mt-1 text-xs text-muted max-w-md mx-auto">Launch a high-performance WordPress site in seconds with automatic database setup.</p>
                <div class="mt-5">
                    <x-ui.button wire:click="openInstallModal" variant="primary" size="sm">
                        Install WordPress Now
                    </x-ui.button>
                </div>
            </x-ui.card>
        @endforelse
    </div>

    <!-- Install Modal -->
    @if ($showInstallModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-lg w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-main mb-1">Install WordPress</h3>
                <p class="text-xs text-muted mb-4">Complete setup takes less than 30 seconds with automatic configuration.</p>

                <form wire:submit.prevent="install" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Target Domain</label>
                        <x-ui.select wire:model.live="domain_id">
                            @foreach ($account->domains as $dom)
                                <option value="{{ $dom->id }}">{{ $dom->domain }}</option>
                            @endforeach
                        </x-ui.select>
                        @error('domain_id') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Site Title</label>
                        <x-ui.input type="text" wire:model="site_title" />
                        @error('site_title') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Admin Username</label>
                            <x-ui.input type="text" wire:model="admin_user" />
                            @error('admin_user') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Admin Email</label>
                            <x-ui.input type="email" wire:model="admin_email" />
                            @error('admin_email') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Admin Password</label>
                        <x-ui.input type="password" wire:model="admin_password" class="font-mono" />
                        @error('admin_password') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <!-- Database settings -->
                    <div class="border-t border-border pt-4">
                        <label class="flex items-center gap-2 text-xs text-main cursor-pointer mb-3">
                            <input type="checkbox" wire:model.live="auto_create_db" class="rounded-sm bg-card border-border text-primary-500 focus:ring-primary-500">
                            <span>Auto-create MariaDB Database &amp; User</span>
                        </label>

                        <div class="grid grid-cols-2 gap-3 bg-shell p-3 rounded-[16px] border border-border">
                            <div>
                                <label class="block text-[11px] font-medium text-muted mb-1">Database Name</label>
                                <input type="text" wire:model="db_name" {{ $auto_create_db ? 'readonly' : '' }}
                                    class="block w-full px-3 py-1.5 rounded-full bg-card border border-border text-main font-mono text-xs outline-hidden" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-medium text-muted mb-1">Database User</label>
                                <input type="text" wire:model="db_user" {{ $auto_create_db ? 'readonly' : '' }}
                                    class="block w-full px-3 py-1.5 rounded-full bg-card border border-border text-main font-mono text-xs outline-hidden" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                        <x-ui.button type="button" wire:click="$set('showInstallModal', false)" variant="ghost" size="sm">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">Install WordPress</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Uninstall Confirmation Modal -->
    @if ($installToDeleteId)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-main mb-1">Uninstall WordPress</h3>
                <p class="text-xs text-muted mb-4">This will permanently delete all WordPress files from the website directory.</p>

                <label class="flex items-center gap-2 text-xs text-rose-500 cursor-pointer mb-6">
                    <input type="checkbox" wire:model="removeDbOnUninstall" class="rounded-sm bg-card border-border text-rose-500 focus:ring-rose-500">
                    <span>Also drop associated MariaDB database and user</span>
                </label>

                <div class="flex items-center justify-end gap-3">
                    <x-ui.button type="button" wire:click="$set('installToDeleteId', null)" variant="ghost" size="sm">Cancel</x-ui.button>
                    <x-ui.button type="button" wire:click="uninstall" variant="danger" size="sm">Confirm Uninstall</x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
