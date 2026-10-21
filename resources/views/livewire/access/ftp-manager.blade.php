<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('accounts.show', $account) }}" class="text-xs text-indigo-400 hover:text-indigo-300 transition">&larr; Back to Account: {{ $account->linux_username }}</a>
            </div>
            <h2 class="text-2xl font-bold text-white tracking-tight">FTP / SFTP Accounts &middot; {{ $account->primary_domain }}</h2>
            <p class="text-sm text-slate-400 mt-1">Manage secondary SFTP sub-accounts restricted to specific website folders (ChrootDirectory).</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <button wire:click="openCreateModal"
                class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none transition">
                + Add SFTP Account
            </button>
        </div>
    </div>

    @if (session()->has('status'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Accounts Table Column -->
        <div class="lg:col-span-2">
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
                <div class="px-6 py-4 border-b border-slate-800 flex justify-between items-center">
                    <h3 class="text-sm font-semibold text-white">Active Sub-Accounts</h3>
                    <span class="text-xs text-slate-400">Limit: {{ $accounts->count() }} / {{ $account->package->max_ftp_accounts ?? 5 }}</span>
                </div>
                <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
                    <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                        <tr>
                            <th scope="col" class="px-6 py-3">Username</th>
                            <th scope="col" class="px-6 py-3">Restricted Directory</th>
                            <th scope="col" class="px-6 py-3">Status</th>
                            <th scope="col" class="px-6 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-300">
                        @forelse ($accounts as $item)
                            <tr class="hover:bg-slate-800/30 transition-colors">
                                <td class="px-6 py-3 font-mono text-xs font-semibold text-white">
                                    {{ $item->username }}
                                </td>
                                <td class="px-6 py-3 font-mono text-xs text-slate-400 truncate max-w-xs">
                                    {{ $item->homedir }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <button wire:click="toggleStatus({{ $item->id }})"
                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $item->is_active ? 'bg-emerald-500/10 text-emerald-400' : 'bg-slate-500/10 text-slate-400' }}">
                                        {{ $item->is_active ? 'Active' : 'Disabled' }}
                                    </button>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-xs">
                                    <button wire:click="deleteFtpAccount({{ $item->id }})" wire:confirm="Remove this SFTP account?"
                                        class="text-rose-400 hover:text-rose-300 font-medium transition">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-slate-500 text-sm">
                                    No secondary SFTP accounts created yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Connection Details Guide -->
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <h3 class="text-base font-semibold text-white mb-3">SFTP Connection Settings</h3>
                <div class="space-y-3 text-xs">
                    <div>
                        <span class="text-slate-400 block mb-0.5">Protocol</span>
                        <span class="font-semibold text-white">SFTP (SSH File Transfer Protocol)</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block mb-0.5">Server Host / IP</span>
                        <span class="font-mono text-indigo-400">{{ $account->server->private_ip }} ({{ $account->primary_domain }})</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block mb-0.5">Port</span>
                        <span class="font-mono text-white">22</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block mb-0.5">Client Support</span>
                        <span class="text-slate-300">FileZilla, Cyberduck, WinSCP, Transmit, VS Code SFTP</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Modal -->
    @if ($isCreating)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/80 flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-white mb-1">Create SFTP Sub-Account</h3>
                <p class="text-xs text-slate-400 mb-4">Grant restricted file access to a contractor or client without exposing your entire home folder.</p>

                <form wire:submit.prevent="createFtpAccount" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Username</label>
                        <div class="flex rounded-lg shadow-sm">
                            <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-slate-700 bg-slate-800 text-slate-400 font-mono text-xs">
                                {{ $account->linux_username }}_
                            </span>
                            <input type="text" wire:model="subuser" placeholder="developer"
                                class="flex-1 min-w-0 block w-full px-3 py-2 rounded-none rounded-r-lg bg-slate-800 border-slate-700 text-white text-sm focus:ring-1 focus:ring-indigo-500 border font-mono">
                        </div>
                        @error('subuser') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Restricted Directory (Homedir)</label>
                        <input type="text" wire:model="homedir"
                            class="w-full font-mono rounded-lg bg-slate-800 border-slate-700 text-white text-xs px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                        @error('homedir') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                        <p class="text-[11px] text-slate-500 mt-1">Must reside strictly inside <span class="font-mono text-slate-400">/home/{{ $account->linux_username }}/</span>.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Password</label>
                        <input type="password" wire:model="password"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                        @error('password') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Confirm Password</label>
                        <input type="password" wire:model="password_confirmation"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-800">
                        <button type="button" wire:click="$set('isCreating', false)"
                            class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold">
                            Cancel
                        </button>
                        <button type="submit"
                            class="px-4 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm">
                            Create Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

