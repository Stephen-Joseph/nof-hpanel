<div class="max-w-3xl mx-auto">
    <x-layout.page-header 
        title="Create Hosting Account" 
        subtitle="Assign an isolated Linux account, package quotas, and provisioning server."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('accounts.index') }}" variant="outline" size="sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Accounts
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <form wire:submit="save" class="space-y-6">
        <x-ui.card class="space-y-4">
            <h3 class="text-base font-semibold text-main border-b border-border pb-3">Account Owner &amp; Environment</h3>

            <!-- Customer selection -->
            <div>
                <label class="block text-xs font-medium text-muted mb-1.5">Customer (Account Owner)</label>
                <x-ui.select wire:model="user_id" required>
                    <option value="">Select customer...</option>
                    @foreach ($customers as $cust)
                        <option value="{{ $cust->id }}">{{ $cust->name }} ({{ $cust->email }})</option>
                    @endforeach
                </x-ui.select>
                @error('user_id') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>

            <!-- Server selection -->
            <div>
                <label class="block text-xs font-medium text-muted mb-1.5">Target Node / Server</label>
                <x-ui.select wire:model="server_id" required>
                    <option value="">Select server...</option>
                    @foreach ($servers as $srv)
                        <option value="{{ $srv->id }}">{{ $srv->name }} &middot; {{ $srv->hostname }} ({{ $srv->private_ip }})</option>
                    @endforeach
                </x-ui.select>
                @error('server_id') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>

            <!-- Package selection -->
            <div>
                <label class="block text-xs font-medium text-muted mb-1.5">Hosting Package</label>
                <x-ui.select wire:model="package_id" required>
                    <option value="">Select package...</option>
                    @foreach ($packages as $pkg)
                        <option value="{{ $pkg->id }}">
                            {{ $pkg->name }} &mdash; {{ round($pkg->storage_mb / 1024, 1) }}GB Disk / {{ $pkg->bandwidth_gb }}GB Bandwidth / {{ $pkg->php_workers }} PHP Workers
                        </option>
                    @endforeach
                </x-ui.select>
                @error('package_id') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>
        </x-ui.card>

        <x-ui.card class="space-y-4">
            <h3 class="text-base font-semibold text-main border-b border-border pb-3">Domain &amp; System Credentials</h3>

            <!-- Primary Domain -->
            <div>
                <label class="block text-xs font-medium text-muted mb-1.5">Primary Domain</label>
                <x-ui.input type="text" wire:model.live.debounce.400ms="primary_domain" required placeholder="example.com" class="font-mono" />
                <p class="text-[11px] text-muted mt-1.5">RFC-compliant lowercase domain name without http/https protocol.</p>
                @error('primary_domain') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>

            <!-- Linux Username -->
            <div>
                <label class="block text-xs font-medium text-muted mb-1.5">Linux Username</label>
                <x-ui.input type="text" wire:model.live.debounce.300ms="linux_username" required maxlength="16" placeholder="e.g. acmeuser" class="font-mono" />
                <p class="text-[11px] text-muted mt-1.5">Must start with a lowercase letter, 3-16 lowercase alphanumeric characters. Owns <code class="text-main font-mono">/home/&lt;user&gt;</code> on the server.</p>
                @error('linux_username') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>
        </x-ui.card>

        <div class="flex items-center justify-end gap-3">
            <x-ui.button href="{{ route('accounts.index') }}" variant="ghost" size="sm">
                Cancel
            </x-ui.button>
            <x-ui.button type="submit" variant="primary" size="sm">
                Create &amp; Enqueue Provisioning
            </x-ui.button>
        </div>
    </form>
</div>
