<div>
    <x-layout.page-header 
        title="SSH Public Keys &middot; {{ $account->primary_domain }}" 
        subtitle="Authenticate securely without passwords. Keys are stored with strict permissions in ~/.ssh/authorized_keys."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('accounts.show', $account) }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Account
                </x-ui.button>
                @if ($account->package->ssh_enabled)
                    <x-ui.chip variant="success" size="sm">
                        &check; Interactive Shell Access Enabled
                    </x-ui.chip>
                @else
                    <x-ui.chip variant="warning" size="sm">
                        SFTP Only (Interactive Shell Disabled)
                    </x-ui.chip>
                @endif
                <x-ui.button wire:click="openCreateModal" variant="primary" size="sm">
                    + Add SSH Key
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="mb-6 rounded-[20px] bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-500 dark:text-rose-400">
            {{ session('error') }}
        </div>
    @endif

    <!-- Keys Table -->
    <x-ui.card class="p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="py-3 px-6">Name</th>
                        <th class="py-3 px-6">Type</th>
                        <th class="py-3 px-6">SHA256 Fingerprint</th>
                        <th class="py-3 px-6">Added</th>
                        <th class="py-3 px-6 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-main">
                    @forelse ($keys as $k)
                        <tr class="hover:bg-shell/40 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap font-medium text-main">
                                {{ $k->name }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-medium bg-shell text-primary-500 border border-border">
                                    {{ $k->key_type }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap font-mono text-xs text-muted">
                                {{ $k->fingerprint }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs text-muted">
                                {{ $k->created_at?->diffForHumans() }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                                <button wire:click="deleteKey({{ $k->id }})" wire:confirm="Remove this SSH key? It will be purged from authorized_keys immediately."
                                    class="text-rose-500 hover:text-rose-600 font-medium transition cursor-pointer">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-muted text-xs">
                                No authorized SSH keys configured. You can add your public key (e.g. ~/.ssh/id_ed25519.pub) above.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <!-- Create Modal -->
    @if ($isCreating)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-lg w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-main mb-1">Add SSH Public Key</h3>
                <p class="text-xs text-muted mb-4">Paste your public key. Supported key types: <span class="font-mono text-main">ssh-ed25519</span>, <span class="font-mono text-main">ecdsa</span>, or <span class="font-mono text-main">ssh-rsa</span> (&ge;2048-bit).</p>

                <form wire:submit.prevent="addKey" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Key Name / Device</label>
                        <x-ui.input type="text" wire:model="name" placeholder="e.g. MacBook Pro, Work Laptop" />
                        @error('name') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Public Key (One line)</label>
                        <x-ui.textarea wire:model="publicKey" rows="5" placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5... user@computer" class="font-mono text-xs" />
                        @error('publicKey') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        <p class="text-[11px] text-muted mt-1.5">Never paste your private key. Only paste the public key file ending in <span class="font-mono text-main">.pub</span>.</p>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-border">
                        <x-ui.button type="button" wire:click="$set('isCreating', false)" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            Add Key
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
