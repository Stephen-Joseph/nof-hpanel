<div class="max-w-3xl mx-auto">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">
                {{ $isEditing ? 'Edit Server Node: ' . $server?->name : 'Register Server Node' }}
            </h2>
            <p class="text-sm text-slate-400 mt-1">Configure node connection parameters, mTLS fingerprint, and capacity.</p>
        </div>
        <a href="{{ route('servers.index') }}" class="text-sm text-slate-400 hover:text-white transition">
            &larr; Back to Servers
        </a>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-4">
            <h3 class="text-lg font-semibold text-white border-b border-slate-800 pb-3">Node Identity &amp; Connectivity</h3>

            <div>
                <label class="block text-sm font-medium text-slate-300">Display Name</label>
                <input type="text" wire:model="name" required placeholder="e.g. Node 1 (Frankfurt)"
                    class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border">
                @error('name') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300">Hostname</label>
                    <input type="text" wire:model="hostname" required placeholder="node1.example.com"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('hostname') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">Private / WireGuard IP</label>
                    <input type="text" wire:model="private_ip" required placeholder="10.0.0.1"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('private_ip') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-300">Agent URL</label>
                <input type="url" wire:model="agent_url" required placeholder="https://10.0.0.1:8443"
                    class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                <span class="text-xs text-slate-500 mt-1 block">Panel communicates with the Go agent exclusively over this mTLS HTTPS address.</span>
                @error('agent_url') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-300">Agent Public Cert SHA-256 Fingerprint</label>
                <input type="text" wire:model="agent_public_cert_fingerprint" placeholder="Optional 64-char hex SHA-256"
                    class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                @error('agent_public_cert_fingerprint') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-300">Node Status</label>
                <select wire:model="status" required
                    class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border">
                    @foreach ($statuses as $st)
                        <option value="{{ $st->value }}">{{ $st->label() }}</option>
                    @endforeach
                </select>
                @error('status') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-4">
            <h3 class="text-lg font-semibold text-white border-b border-slate-800 pb-3">Node Hardware Capacity</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300">Max Hosting Accounts</label>
                    <input type="number" wire:model="max_accounts" required min="1" max="10000"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('max_accounts') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">CPU Cores</label>
                    <input type="number" wire:model="cpu_cores" min="1" max="256" placeholder="e.g. 16"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('cpu_cores') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">Total RAM (MB)</label>
                    <input type="number" wire:model="memory_mb" min="512" placeholder="e.g. 32768 (32GB)"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('memory_mb') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">Total Storage (MB)</label>
                    <input type="number" wire:model="storage_mb" min="1024" placeholder="e.g. 1000000 (1TB)"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('storage_mb') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-4">
            <a href="{{ route('servers.index') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-lg text-sm font-medium border border-slate-700 transition">
                Cancel
            </a>
            <button type="submit"
                class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium shadow transition">
                {{ $isEditing ? 'Save Changes' : 'Register Node' }}
            </button>
        </div>
    </form>
</div>

