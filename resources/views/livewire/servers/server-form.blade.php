<div class="max-w-3xl mx-auto">
    <x-layout.page-header 
        title="{{ $isEditing ? 'Edit Server Node: ' . $server?->name : 'Register Server Node' }}" 
        subtitle="Configure node connection parameters, mTLS fingerprint, and capacity."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('servers.index') }}" variant="outline" size="sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Servers
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <form wire:submit="save" novalidate class="space-y-6">
        <x-ui.card class="space-y-4">
            <h3 class="text-base font-semibold text-main border-b border-border pb-3">Node Identity &amp; Connectivity</h3>

            <div>
                <label class="block text-xs font-medium text-muted mb-1.5">Display Name</label>
                <x-ui.input type="text" wire:model="name" required placeholder="e.g. Node 1 (Frankfurt)" />
                @error('name') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Hostname</label>
                    <x-ui.input type="text" wire:model="hostname" required placeholder="node1.example.com" class="font-mono" />
                    @error('hostname') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Private / WireGuard IP</label>
                    <x-ui.input type="text" wire:model="private_ip" required placeholder="10.0.0.1" class="font-mono" />
                    @error('private_ip') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-muted mb-1.5">Agent URL</label>
                <x-ui.input type="url" wire:model="agent_url" required placeholder="https://10.0.0.1:8443" class="font-mono" />
                <span class="text-[11px] text-muted mt-1 block">Panel communicates with the Go agent exclusively over this mTLS HTTPS address.</span>
                @error('agent_url') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-muted mb-1.5">Agent Public Cert SHA-256 Fingerprint</label>
                <x-ui.input type="text" wire:model="agent_public_cert_fingerprint" placeholder="Optional 64-char hex SHA-256" class="font-mono" />
                @error('agent_public_cert_fingerprint') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-muted mb-1.5">Node Status</label>
                <x-ui.select wire:model="status" required>
                    @foreach ($statuses as $st)
                        <option value="{{ $st->value }}">{{ $st->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>
        </x-ui.card>

        <x-ui.card class="space-y-4">
            <h3 class="text-base font-semibold text-main border-b border-border pb-3">Node Hardware Capacity</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Max Hosting Accounts</label>
                    <x-ui.input type="number" wire:model="max_accounts" required min="1" max="10000" class="font-mono" />
                    @error('max_accounts') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">CPU Cores</label>
                    <x-ui.input type="number" wire:model="cpu_cores" min="1" max="256" placeholder="e.g. 16" class="font-mono" />
                    @error('cpu_cores') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Total RAM (MB)</label>
                    <x-ui.input type="number" wire:model="memory_mb" min="512" placeholder="e.g. 32768 (32GB)" class="font-mono" />
                    @error('memory_mb') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Total Storage (MB)</label>
                    <x-ui.input type="number" wire:model="storage_mb" min="1024" placeholder="e.g. 1000000 (1TB)" class="font-mono" />
                    @error('storage_mb') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-ui.card>

        <div class="flex items-center justify-end gap-3">
            <x-ui.button href="{{ route('servers.index') }}" variant="ghost" size="sm">
                Cancel
            </x-ui.button>
            <x-ui.button type="submit" variant="primary" size="sm">
                {{ $isEditing ? 'Save Changes' : 'Register Node' }}
            </x-ui.button>
        </div>
    </form>
</div>
