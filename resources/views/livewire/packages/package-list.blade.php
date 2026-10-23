<div>
    <x-layout.page-header 
        title="Hosting Packages" 
        subtitle="Resource limits, quotas, and service profiles for hosting accounts."
    >
        @if ($canCreate)
            <x-slot:actions>
                <x-ui.button href="{{ route('packages.create') }}" variant="primary" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Create Package
                </x-ui.button>
            </x-slot:actions>
        @endif
    </x-layout.page-header>

    <!-- Search filter -->
    <x-ui.card class="p-3 mb-6 flex items-center justify-between">
        <div class="w-full sm:w-80">
            <x-ui.input type="text" wire:model.live.debounce.300ms="search" placeholder="Search packages by name..." />
        </div>
    </x-ui.card>

    <!-- Table -->
    <x-ui.card class="p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="py-3 px-6">Package Name</th>
                        <th class="py-3 px-6">Storage &amp; Quota</th>
                        <th class="py-3 px-6">Bandwidth</th>
                        <th class="py-3 px-6">Websites &amp; DBs</th>
                        <th class="py-3 px-6">PHP Workers</th>
                        <th class="py-3 px-6">Accounts</th>
                        <th class="py-3 px-6 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-main">
                    @forelse ($packages as $pkg)
                        <tr class="hover:bg-shell/40 transition">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-semibold text-main">{{ $pkg->name }}</span>
                                @if ($pkg->reseller_owner_id)
                                    <span class="block text-xs text-primary-500 font-medium">Reseller: {{ $pkg->resellerOwner?->name }}</span>
                                @else
                                    <span class="block text-xs text-muted">Global System Package</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs">
                                <span class="text-main font-mono font-medium">{{ round($pkg->storage_mb / 1024, 1) }} GB</span>
                                <span class="text-muted block text-[11px]">{{ number_format($pkg->inodes) }} inodes</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs text-main font-mono">
                                {{ $pkg->bandwidth_gb }} GB / mo
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs">
                                <span class="text-muted">Websites: <strong class="text-main">{{ $pkg->max_websites }}</strong></span>
                                <span class="block text-muted">Databases: <strong class="text-main">{{ $pkg->max_databases }}</strong></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs text-muted">
                                <span class="font-mono text-main font-medium">{{ $pkg->php_workers }}</span> workers
                                <span class="text-muted block text-[11px]">{{ $pkg->php_memory_mb }} MB limit</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs font-mono">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-shell text-primary-500 border border-border">
                                    {{ $pkg->accounts_count }} accounts
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $pkg)
                                        <a href="{{ route('packages.edit', $pkg) }}" class="text-primary-500 hover:text-primary-600 font-medium">
                                            Edit
                                        </a>
                                    @endcan

                                    @can('delete', $pkg)
                                        <button type="button" wire:click="deletePackage({{ $pkg->id }})" wire:confirm="Are you sure you want to delete this package?"
                                            class="text-rose-500 hover:text-rose-600 font-medium cursor-pointer">
                                            Delete
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-muted text-xs">
                                No hosting packages found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($packages->hasPages())
            <div class="px-6 py-4 border-t border-border">
                {{ $packages->links() }}
            </div>
        @endif
    </x-ui.card>
</div>
