<div class="max-w-4xl mx-auto">
    <x-layout.page-header 
        title="{{ $isEditing ? 'Edit Package: ' . $package?->name : 'Create New Package' }}" 
        subtitle="Configure limits, quotas, and runtime environments."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('packages.index') }}" variant="outline" size="sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Packages
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <form wire:submit="save" novalidate class="space-y-6">
        <!-- Basic Info -->
        <x-ui.card>
            <h3 class="text-base font-semibold text-main mb-4 border-b border-border pb-3">General Configuration</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-muted mb-1.5">Package Name</label>
                    <x-ui.input type="text" wire:model="name" required />
                    @error('name') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-ui.card>

        <!-- Storage & Quotas -->
        <x-ui.card>
            <h3 class="text-base font-semibold text-main mb-4 border-b border-border pb-3">Storage &amp; Quota Limits</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Disk Quota (MB)</label>
                    <x-ui.input type="number" wire:model="storage_mb" required min="100" class="font-mono" />
                    <span class="text-[11px] text-muted mt-1 block">{{ round($storage_mb / 1024, 2) }} GB</span>
                    @error('storage_mb') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Inode Limit</label>
                    <x-ui.input type="number" wire:model="inodes" required min="1000" class="font-mono" />
                    @error('inodes') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Monthly Bandwidth (GB)</label>
                    <x-ui.input type="number" wire:model="bandwidth_gb" required min="1" class="font-mono" />
                    @error('bandwidth_gb') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-ui.card>

        <!-- Websites & Databases Limits -->
        <x-ui.card>
            <h3 class="text-base font-semibold text-main mb-4 border-b border-border pb-3">Domains &amp; Database Limits</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Max Websites</label>
                    <x-ui.input type="number" wire:model="max_websites" required min="1" class="font-mono" />
                    @error('max_websites') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Max Subdomains</label>
                    <x-ui.input type="number" wire:model="max_subdomains" required min="0" class="font-mono" />
                    @error('max_subdomains') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Max Databases</label>
                    <x-ui.input type="number" wire:model="max_databases" required min="0" class="font-mono" />
                    @error('max_databases') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Max DB Users</label>
                    <x-ui.input type="number" wire:model="max_db_users" required min="0" class="font-mono" />
                    @error('max_db_users') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Max FTP / SFTP Accounts</label>
                    <x-ui.input type="number" wire:model="max_ftp_accounts" required min="0" class="font-mono" />
                    @error('max_ftp_accounts') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Max Cron Jobs</label>
                    <x-ui.input type="number" wire:model="max_cron_jobs" required min="0" class="font-mono" />
                    @error('max_cron_jobs') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-ui.card>

        <!-- PHP-FPM & System Limits -->
        <x-ui.card>
            <h3 class="text-base font-semibold text-main mb-4 border-b border-border pb-3">PHP Runtime &amp; Access Controls</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">PHP Workers (max_children)</label>
                    <x-ui.input type="number" wire:model="php_workers" required min="1" max="100" class="font-mono" />
                    @error('php_workers') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">PHP Memory Limit (MB)</label>
                    <x-ui.input type="number" wire:model="php_memory_mb" required min="64" max="4096" class="font-mono" />
                    @error('php_memory_mb') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Backups Retained</label>
                    <x-ui.input type="number" wire:model="backups_retained" required min="0" max="60" class="font-mono" />
                    @error('backups_retained') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="sm:col-span-3">
                    <label class="block text-xs font-medium text-muted mb-2">Allowed PHP Versions</label>
                    <div class="flex flex-wrap gap-4">
                        @foreach ($availablePhpVersions as $ver)
                            <label class="inline-flex items-center cursor-pointer">
                                <input type="checkbox" wire:model="allowed_php_versions" value="{{ $ver }}"
                                    class="h-4 w-4 rounded-sm bg-card border-border text-primary-500 focus:ring-primary-500">
                                <span class="ml-2 text-xs text-main font-mono">PHP {{ $ver }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('allowed_php_versions') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="sm:col-span-3 pt-2 flex flex-wrap items-center gap-6">
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" wire:model="ssh_enabled"
                            class="h-4 w-4 rounded-sm bg-card border-border text-primary-500 focus:ring-primary-500">
                        <span class="ml-2 text-xs text-main">Enable Jailed SSH Shell Access</span>
                    </label>

                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" wire:model="git_enabled"
                            class="h-4 w-4 rounded-sm bg-card border-border text-primary-500 focus:ring-primary-500">
                        <span class="ml-2 text-xs text-main">Enable Git Deployment Features</span>
                    </label>
                </div>
            </div>
        </x-ui.card>

        <div class="flex items-center justify-end gap-3">
            <x-ui.button href="{{ route('packages.index') }}" variant="ghost" size="sm">
                Cancel
            </x-ui.button>
            <x-ui.button type="submit" variant="primary" size="sm">
                {{ $isEditing ? 'Save Changes' : 'Create Package' }}
            </x-ui.button>
        </div>
    </form>
</div>
