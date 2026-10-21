<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('accounts.show', $account) }}" class="text-xs text-indigo-400 hover:text-indigo-300 transition">&larr; Back to Account: {{ $account->linux_username }}</a>
            </div>
            <h2 class="text-2xl font-bold text-white tracking-tight">SSH Public Keys &middot; {{ $account->primary_domain }}</h2>
            <p class="text-sm text-slate-400 mt-1">Authenticate securely without passwords. Keys are stored with strict permissions in <span class="font-mono text-slate-300">~/.ssh/authorized_keys</span>.</p>
        </div>
        <div class="mt-4 sm:mt-0 flex items-center gap-3">
            @if ($account->package->ssh_enabled)
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                    &check; Interactive Shell Access Enabled
                </span>
            @else
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20">
                    SFTP Only (Interactive Shell Disabled)
                </span>
            @endif
            <button wire:click="openCreateModal"
                class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none transition">
                + Add SSH Key
            </button>
        </div>
    </div>

    @if (session()->has('status'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400">
            {{ session('status') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="mb-6 rounded-lg bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-400">
            {{ session('error') }}
        </div>
    @endif

    <!-- Keys Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                <tr>
                    <th scope="col" class="px-6 py-3">Name</th>
                    <th scope="col" class="px-6 py-3">Type</th>
                    <th scope="col" class="px-6 py-3">SHA256 Fingerprint</th>
                    <th scope="col" class="px-6 py-3">Added</th>
                    <th scope="col" class="px-6 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800 text-slate-300">
                @forelse ($keys as $k)
                    <tr class="hover:bg-slate-800/30 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap font-medium text-white">
                            {{ $k->name }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-medium bg-slate-800 text-slate-300 border border-slate-700">
                                {{ $k->key_type }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-xs text-slate-400">
                            {{ $k->fingerprint }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-slate-400">
                            {{ $k->created_at?->diffForHumans() }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                            <button wire:click="deleteKey({{ $k->id }})" wire:confirm="Remove this SSH key? It will be purged from authorized_keys immediately."
                                class="text-rose-400 hover:text-rose-300 font-medium transition">
                                Delete
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-slate-500 text-sm">
                            No authorized SSH keys configured. You can add your public key (e.g. ~/.ssh/id_ed25519.pub) above.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Create Modal -->
    @if ($isCreating)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/80 flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-lg w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-white mb-1">Add SSH Public Key</h3>
                <p class="text-xs text-slate-400 mb-4">Paste your public key. Supported key types: <span class="font-mono text-slate-300">ssh-ed25519</span>, <span class="font-mono text-slate-300">ecdsa</span>, or <span class="font-mono text-slate-300">ssh-rsa</span> (&ge;2048-bit).</p>

                <form wire:submit.prevent="addKey" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Key Name / Device</label>
                        <input type="text" wire:model="name" placeholder="e.g. MacBook Pro, Work Laptop"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                        @error('name') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Public Key (One line)</label>
                        <textarea wire:model="publicKey" rows="5" placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5... user@computer"
                            class="w-full font-mono rounded-lg bg-slate-800 border-slate-700 text-white text-xs px-3 py-2 border focus:ring-1 focus:ring-indigo-500"></textarea>
                        @error('publicKey') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                        <p class="text-[11px] text-slate-500 mt-1">Never paste your private key. Only paste the public key file ending in <span class="font-mono">.pub</span>.</p>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-800">
                        <button type="button" wire:click="$set('isCreating', false)"
                            class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold">
                            Cancel
                        </button>
                        <button type="submit"
                            class="px-4 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm">
                            Add Key
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

