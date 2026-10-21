<div class="max-w-3xl mx-auto">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">Create Hosting Account</h2>
            <p class="text-sm text-slate-400 mt-1">Assign an isolated Linux account, package quotas, and provisioning server.</p>
        </div>
        <a href="{{ route('accounts.index') }}" class="text-sm text-slate-400 hover:text-white transition">
            &larr; Back to Accounts
        </a>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-4">
            <h3 class="text-lg font-semibold text-white border-b border-slate-800 pb-3">Account Owner &amp; Environment</h3>

            <!-- Customer selection -->
            <div>
                <label class="block text-sm font-medium text-slate-300">Customer (Account Owner)</label>
                <select wire:model="user_id" required
                    class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border">
                    <option value="">Select customer...</option>
                    @foreach ($customers as $cust)
                        <option value="{{ $cust->id }}">{{ $cust->name }} ({{ $cust->email }})</option>
                    @endforeach
                </select>
                @error('user_id') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
            </div>

            <!-- Server selection -->
            <div>
                <label class="block text-sm font-medium text-slate-300">Target Node / Server</label>
                <select wire:model="server_id" required
                    class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border">
                    <option value="">Select server...</option>
                    @foreach ($servers as $srv)
                        <option value="{{ $srv->id }}">{{ $srv->name }} &middot; {{ $srv->hostname }} ({{ $srv->private_ip }})</option>
                    @endforeach
                </select>
                @error('server_id') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
            </div>

            <!-- Package selection -->
            <div>
                <label class="block text-sm font-medium text-slate-300">Hosting Package</label>
                <select wire:model="package_id" required
                    class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border">
                    <option value="">Select package...</option>
                    @foreach ($packages as $pkg)
                        <option value="{{ $pkg->id }}">
                            {{ $pkg->name }} &mdash; {{ round($pkg->storage_mb / 1024, 1) }}GB Disk / {{ $pkg->bandwidth_gb }}GB Bandwidth / {{ $pkg->php_workers }} PHP Workers
                        </option>
                    @endforeach
                </select>
                @error('package_id') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-4">
            <h3 class="text-lg font-semibold text-white border-b border-slate-800 pb-3">Domain &amp; System Credentials</h3>

            <!-- Primary Domain -->
            <div>
                <label class="block text-sm font-medium text-slate-300">Primary Domain</label>
                <input type="text" wire:model.live.debounce.400ms="primary_domain" required placeholder="example.com"
                    class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border font-mono">
                <p class="text-xs text-slate-500 mt-1">RFC-compliant lowercase domain name without http/https protocol.</p>
                @error('primary_domain') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
            </div>

            <!-- Linux Username -->
            <div>
                <label class="block text-sm font-medium text-slate-300">Linux Username</label>
                <input type="text" wire:model.live.debounce.300ms="linux_username" required maxlength="16" placeholder="e.g. acmeuser"
                    class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border font-mono">
                <p class="text-xs text-slate-500 mt-1">Must start with a lowercase letter, 3-16 lowercase alphanumeric characters. Owns <code class="text-slate-400">/home/&lt;user&gt;</code> on the server.</p>
                @error('linux_username') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="flex items-center justify-end gap-4">
            <a href="{{ route('accounts.index') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-lg text-sm font-medium border border-slate-700 transition">
                Cancel
            </a>
            <button type="submit"
                class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium shadow transition">
                Create &amp; Enqueue Provisioning
            </button>
        </div>
    </form>
</div>

