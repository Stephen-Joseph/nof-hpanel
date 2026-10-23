<div>
    <!-- Header -->
    <x-layout.page-header 
        title="Protected Directories &middot; {{ $account->primary_domain }}" 
        subtitle="Restrict web access to specific URL paths using HTTP Basic Authentication (.htpasswd) outside the public web root."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('accounts.show', $account) }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Account
                </x-ui.button>
                <x-ui.button wire:click="openCreateModal" variant="primary" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Protect Directory
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Status Alerts -->
    @if (session()->has('success'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('success') }}
        </div>
    @endif

    <!-- Directories List -->
    <div class="space-y-4">
        @forelse ($directories as $dir)
            <x-ui.card>
                <div class="sm:flex sm:items-start sm:justify-between border-b border-border pb-4 mb-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-3">
                            <h3 class="text-base font-bold text-main font-mono">{{ $dir->path }}</h3>
                            <span class="text-xs text-muted font-sans">on <strong class="text-main">{{ $dir->domain->domain }}</strong></span>
                            @if($dir->is_active)
                                <x-ui.chip variant="success" size="sm">Active</x-ui.chip>
                            @else
                                <x-ui.chip variant="warning" size="sm">Disabled</x-ui.chip>
                            @endif
                        </div>
                        <p class="text-xs text-muted mt-1.5">
                            Realm: <span class="text-main italic">&ldquo;{{ $dir->realm }}&rdquo;</span> &bull; 
                            Storage: <span class="text-muted font-mono">/home/{{ $account->linux_username }}/.htpasswds/{{ $dir->htpasswd_filename }}</span>
                        </p>
                    </div>

                    <div class="mt-4 sm:mt-0 flex items-center gap-2">
                        <x-ui.button wire:click="openAddUserModal({{ $dir->id }})" variant="outline" size="sm">
                            Add User
                        </x-ui.button>
                        <x-ui.button wire:click="toggleActive({{ $dir->id }})" variant="outline" size="sm">
                            {{ $dir->is_active ? 'Disable' : 'Enable' }}
                        </x-ui.button>
                        <button wire:click="deleteDirectory({{ $dir->id }})" wire:confirm="Remove password protection for {{ $dir->path }}?" class="w-8 h-8 rounded-full border border-border flex items-center justify-center text-muted hover:text-rose-500 hover:border-rose-500/30 transition cursor-pointer">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Users List -->
                <div>
                    <h4 class="text-xs font-semibold text-muted uppercase tracking-wider mb-2.5">Authorized Users</h4>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($dir->users as $user)
                            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-shell border border-border text-xs">
                                <span class="font-mono text-main font-medium">{{ $user['username'] }}</span>
                                @if(count($dir->users) > 1)
                                    <button wire:click="removeUser({{ $dir->id }}, '{{ $user['username'] }}')" wire:confirm="Remove user {{ $user['username'] }}?" class="text-muted hover:text-rose-500 text-xs font-bold leading-none cursor-pointer">&times;</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card class="p-12 text-center">
                <div class="w-12 h-12 rounded-full bg-primary-100 dark:bg-primary-950/40 text-primary-500 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-main">No Protected Directories</h3>
                <p class="mt-1 text-xs text-muted max-w-md mx-auto">Lock down administrative panels, staging directories, or private folders with username/password authentication.</p>
                <div class="mt-5">
                    <x-ui.button wire:click="openCreateModal" variant="primary" size="sm">
                        Protect a Directory
                    </x-ui.button>
                </div>
            </x-ui.card>
        @endforelse
    </div>

    <!-- Create Protected Directory Modal -->
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-lg w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-main mb-1">Protect Directory</h3>
                <p class="text-xs text-muted mb-4">Credentials are safely stored outside your public web root in .htpasswds.</p>

                <form wire:submit.prevent="createDirectory" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Target Domain</label>
                        <x-ui.select wire:model="domain_id">
                            @foreach ($account->domains as $dom)
                                <option value="{{ $dom->id }}">{{ $dom->domain }}</option>
                            @endforeach
                        </x-ui.select>
                        @error('domain_id') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Directory Path</label>
                            <x-ui.input type="text" wire:model="path" placeholder="/admin" />
                            @error('path') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Prompt / Realm Name</label>
                            <x-ui.input type="text" wire:model="realm" placeholder="Restricted Area" />
                            @error('realm') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Initial Username</label>
                            <x-ui.input type="text" wire:model="new_username" placeholder="admin" />
                            @error('new_username') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Password</label>
                            <x-ui.input type="password" wire:model="new_password" />
                            @error('new_password') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                        <x-ui.button type="button" wire:click="$set('showCreateModal', false)" variant="ghost" size="sm">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">Protect Directory</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Add User Modal -->
    @if ($showAddUserModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-sm w-full p-6 shadow-2xl">
                <h3 class="text-base font-bold text-main mb-1">Add Authorized User</h3>
                <p class="text-xs text-muted mb-4">Add additional credentials to access this protected folder.</p>

                <form wire:submit.prevent="addUser" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Username</label>
                        <x-ui.input type="text" wire:model="extra_username" placeholder="john" />
                        @error('extra_username') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Password</label>
                        <x-ui.input type="password" wire:model="extra_password" />
                        @error('extra_password') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                        <x-ui.button type="button" wire:click="$set('showAddUserModal', false)" variant="ghost" size="sm">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">Add User</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
