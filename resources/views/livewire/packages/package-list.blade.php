<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">Hosting Packages</h2>
            <p class="text-sm text-slate-400 mt-1">Resource limits, quotas, and service profiles for hosting accounts.</p>
        </div>
        @if ($canCreate)
            <div class="mt-4 sm:mt-0">
                <a href="{{ route('packages.create') }}"
                    class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 transition">
                    Create Package
                </a>
            </div>
        @endif
    </div>

    <!-- Search filter -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-6">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search packages by name..."
            class="w-full sm:w-80 rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border">
    </div>

    <!-- Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                <tr>
                    <th scope="col" class="px-6 py-3">Package Name</th>
                    <th scope="col" class="px-6 py-3">Storage &amp; Quota</th>
                    <th scope="col" class="px-6 py-3">Bandwidth</th>
                    <th scope="col" class="px-6 py-3">Websites &amp; DBs</th>
                    <th scope="col" class="px-6 py-3">PHP Workers</th>
                    <th scope="col" class="px-6 py-3">Accounts</th>
                    <th scope="col" class="px-6 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800 text-slate-300">
                @forelse ($packages as $pkg)
                    <tr class="hover:bg-slate-800/30 transition">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="font-semibold text-white">{{ $pkg->name }}</span>
                            @if ($pkg->reseller_owner_id)
                                <span class="block text-xs text-indigo-400">Reseller: {{ $pkg->resellerOwner?->name }}</span>
                            @else
                                <span class="block text-xs text-slate-500">Global System Package</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs">
                            <span class="text-white font-mono">{{ round($pkg->storage_mb / 1024, 1) }} GB</span>
                            <span class="text-slate-500 block">{{ number_format($pkg->inodes) }} inodes</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-white font-mono">
                            {{ $pkg->bandwidth_gb }} GB / mo
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs">
                            <span class="text-slate-300">Websites: <strong class="text-white">{{ $pkg->max_websites }}</strong></span>
                            <span class="block text-slate-400">Databases: <strong class="text-white">{{ $pkg->max_databases }}</strong></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-slate-300">
                            <span class="font-mono text-white">{{ $pkg->php_workers }}</span> workers
                            <span class="text-slate-500 block">{{ $pkg->php_memory_mb }} MB limit</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs font-mono">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-800 text-slate-300 border border-slate-700">
                                {{ $pkg->accounts_count }} accounts
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                            <div class="flex items-center justify-end gap-3">
                                @can('update', $pkg)
                                    <a href="{{ route('packages.edit', $pkg) }}" class="text-indigo-400 hover:text-indigo-300 font-medium">
                                        Edit
                                    </a>
                                @endcan

                                @can('delete', $pkg)
                                    <button type="button" wire:click="deletePackage({{ $pkg->id }})" wire:confirm="Are you sure you want to delete this package?"
                                        class="text-rose-400 hover:text-rose-300 font-medium">
                                        Delete
                                    </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-slate-500 text-sm">
                            No hosting packages found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($packages->hasPages())
            <div class="px-6 py-4 border-t border-slate-800">
                {{ $packages->links() }}
            </div>
        @endif
    </div>
</div>

