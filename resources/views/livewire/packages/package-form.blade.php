<div class="max-w-4xl mx-auto">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">
                {{ $isEditing ? 'Edit Package: ' . $package?->name : 'Create New Package' }}
            </h2>
            <p class="text-sm text-slate-400 mt-1">Configure limits, quotas, and runtime environments.</p>
        </div>
        <a href="{{ route('packages.index') }}" class="text-sm text-slate-400 hover:text-white transition">
            &larr; Back to Packages
        </a>
    </div>

    <form wire:submit="save" class="space-y-6">
        <!-- Basic Info -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h3 class="text-lg font-semibold text-white mb-4">General Configuration</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-300">Package Name</label>
                    <input type="text" wire:model="name" required
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border">
                    @error('name') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <!-- Storage & Quotas -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h3 class="text-lg font-semibold text-white mb-4">Storage &amp; Quota Limits</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300">Disk Quota (MB)</label>
                    <input type="number" wire:model="storage_mb" required min="100"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    <span class="text-[11px] text-slate-500 mt-0.5 block">{{ round($storage_mb / 1024, 2) }} GB</span>
                    @error('storage_mb') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">Inode Limit</label>
                    <input type="number" wire:model="inodes" required min="1000"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('inodes') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">Monthly Bandwidth (GB)</label>
                    <input type="number" wire:model="bandwidth_gb" required min="1"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('bandwidth_gb') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <!-- Websites & Databases Limits -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h3 class="text-lg font-semibold text-white mb-4">Domains &amp; Database Limits</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300">Max Websites</label>
                    <input type="number" wire:model="max_websites" required min="1"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('max_websites') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">Max Subdomains</label>
                    <input type="number" wire:model="max_subdomains" required min="0"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('max_subdomains') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">Max Databases</label>
                    <input type="number" wire:model="max_databases" required min="0"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('max_databases') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">Max DB Users</label>
                    <input type="number" wire:model="max_db_users" required min="0"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('max_db_users') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">Max FTP / SFTP Accounts</label>
                    <input type="number" wire:model="max_ftp_accounts" required min="0"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('max_ftp_accounts') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">Max Cron Jobs</label>
                    <input type="number" wire:model="max_cron_jobs" required min="0"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('max_cron_jobs') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <!-- PHP-FPM & System Limits -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h3 class="text-lg font-semibold text-white mb-4">PHP Runtime &amp; Access Controls</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300">PHP Workers (max_children)</label>
                    <input type="number" wire:model="php_workers" required min="1" max="100"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('php_workers') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">PHP Memory Limit (MB)</label>
                    <input type="number" wire:model="php_memory_mb" required min="64" max="4096"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('php_memory_mb') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300">Backups Retained</label>
                    <input type="number" wire:model="backups_retained" required min="0" max="60"
                        class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border font-mono">
                    @error('backups_retained') <span class="text-xs text-rose-400">{{ $message }}</span> @enderror
                </div>

                <div class="sm:col-span-3">
                    <label class="block text-sm font-medium text-slate-300 mb-2">Allowed PHP Versions</label>
                    <div class="flex flex-wrap gap-4">
                        @foreach ($availablePhpVersions as $ver)
                            <label class="inline-flex items-center">
                                <input type="checkbox" wire:model="allowed_php_versions" value="{{ $ver }}"
                                    class="h-4 w-4 rounded bg-slate-800 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                                <span class="ml-2 text-sm text-slate-300 font-mono">PHP {{ $ver }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('allowed_php_versions') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="sm:col-span-3 pt-2 flex items-center gap-6">
                    <label class="inline-flex items-center">
                        <input type="checkbox" wire:model="ssh_enabled"
                            class="h-4 w-4 rounded bg-slate-800 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                        <span class="ml-2 text-sm text-slate-300">Enable Jailed SSH Shell Access</span>
                    </label>

                    <label class="inline-flex items-center">
                        <input type="checkbox" wire:model="git_enabled"
                            class="h-4 w-4 rounded bg-slate-800 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                        <span class="ml-2 text-sm text-slate-300">Enable Git Deployment Features</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-4">
            <a href="{{ route('packages.index') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-lg text-sm font-medium border border-slate-700 transition">
                Cancel
            </a>
            <button type="submit"
                class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium shadow transition">
                {{ $isEditing ? 'Save Changes' : 'Create Package' }}
            </button>
        </div>
    </form>
</div>

