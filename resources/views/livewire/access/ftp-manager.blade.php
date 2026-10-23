<div>
    <x-layout.page-header 
        title="FTP / SFTP Accounts &middot; {{ $account->primary_domain }}" 
        subtitle="Manage secondary SFTP sub-accounts restricted to specific website folders (ChrootDirectory)."
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
                    + Add SFTP Account
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Accounts Table Column -->
        <div class="lg:col-span-2">
            <x-ui.card class="p-0 overflow-hidden">
                <div class="px-6 py-4 border-b border-border flex justify-between items-center">
                    <h3 class="text-sm font-semibold text-main">Active Sub-Accounts</h3>
                    <span class="text-xs text-muted">Limit: {{ $accounts->count() }} / {{ $account->package->max_ftp_accounts ?? 5 }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                                <th class="py-3 px-6">Username</th>
                                <th class="py-3 px-6">Restricted Directory</th>
                                <th class="py-3 px-6">Status</th>
                                <th class="py-3 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-main">
                            @forelse ($accounts as $item)
                                <tr class="hover:bg-shell/40 transition-colors">
                                    <td class="px-6 py-3 font-mono text-xs font-semibold text-main">
                                        {{ $item->username }}
                                    </td>
                                    <td class="px-6 py-3 font-mono text-xs text-muted truncate max-w-xs">
                                        {{ $item->homedir }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <button wire:click="toggleStatus({{ $item->id }})" class="cursor-pointer">
                                            <x-ui.chip :variant="$item->is_active ? 'success' : 'warning'" size="sm">
                                                {{ $item->is_active ? 'Active' : 'Disabled' }}
                                            </x-ui.chip>
                                        </button>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right text-xs">
                                        <button wire:click="deleteFtpAccount({{ $item->id }})" wire:confirm="Remove this SFTP account?"
                                            class="text-rose-500 hover:text-rose-600 font-medium transition cursor-pointer">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-muted text-xs">
                                        No secondary SFTP accounts created yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        <!-- Connection Details Guide -->
        <div class="lg:col-span-1 space-y-6">
            <x-ui.card>
                <h3 class="text-base font-semibold text-main mb-3">SFTP Connection Settings</h3>
                <div class="space-y-3 text-xs">
                    <div>
                        <span class="text-muted block mb-0.5">Protocol</span>
                        <span class="font-semibold text-main">SFTP (SSH File Transfer Protocol)</span>
                    </div>
                    <div>
                        <span class="text-muted block mb-0.5">Server Host / IP</span>
                        <span class="font-mono text-primary-500 font-semibold">{{ $account->server->private_ip }} ({{ $account->primary_domain }})</span>
                    </div>
                    <div>
                        <span class="text-muted block mb-0.5">Port</span>
                        <span class="font-mono text-main">22</span>
                    </div>
                    <div>
                        <span class="text-muted block mb-0.5">Client Support</span>
                        <span class="text-muted">FileZilla, Cyberduck, WinSCP, Transmit, VS Code SFTP</span>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>

    <!-- Create Modal -->
    @if ($isCreating)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-main mb-1">Create SFTP Sub-Account</h3>
                <p class="text-xs text-muted mb-4">Grant restricted file access to a contractor or client without exposing your entire home folder.</p>

                <form wire:submit.prevent="createFtpAccount" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Username</label>
                        <div class="flex rounded-full border border-border bg-card overflow-hidden shadow-xs focus-within:border-primary-500 focus-within:ring-2 focus-within:ring-primary-500/20">
                            <span class="inline-flex items-center px-3.5 bg-shell text-muted font-mono text-xs border-r border-border">
                                {{ $account->linux_username }}_
                            </span>
                            <input type="text" wire:model="subuser" placeholder="developer"
                                class="flex-1 min-w-0 block w-full px-3.5 py-2 bg-transparent text-main text-xs font-mono outline-hidden">
                        </div>
                        @error('subuser') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Restricted Directory (Homedir)</label>
                        <x-ui.input type="text" wire:model="homedir" class="font-mono" />
                        @error('homedir') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        <p class="text-[11px] text-muted mt-1">Must reside strictly inside <span class="font-mono text-main">/home/{{ $account->linux_username }}/</span>.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Password</label>
                        <x-ui.input type="password" wire:model="password" />
                        @error('password') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Confirm Password</label>
                        <x-ui.input type="password" wire:model="password_confirmation" />
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-border">
                        <x-ui.button type="button" wire:click="$set('isCreating', false)" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            Create Account
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
