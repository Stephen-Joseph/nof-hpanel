<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">Hosting Accounts</h2>
            <p class="text-sm text-slate-400 mt-1">Isolated Linux accounts, PHP pools, and vhost environments.</p>
        </div>
        @if ($canCreate)
            <div class="mt-4 sm:mt-0">
                <a href="{{ route('accounts.create') }}"
                    class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 transition">
                    New Account
                </a>
            </div>
        @endif
    </div>

    <!-- Filters -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Search Domain / Username / User</label>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Filter accounts..."
                class="w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border">
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Status</label>
            <select wire:model.live="status"
                class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border">
                <option value="">All Statuses</option>
                @foreach ($statuses as $st)
                    <option value="{{ $st->value }}">{{ $st->label() }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Server</label>
            <select wire:model.live="serverId"
                class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border">
                <option value="">All Servers</option>
                @foreach ($servers as $srv)
                    <option value="{{ $srv->id }}">{{ $srv->name }} ({{ $srv->hostname }})</option>
                @endforeach
            </select>
        </div>
    </div>

    <!-- Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                <tr>
                    <th scope="col" class="px-6 py-3">Primary Domain</th>
                    <th scope="col" class="px-6 py-3">Linux User</th>
                    <th scope="col" class="px-6 py-3">Owner</th>
                    <th scope="col" class="px-6 py-3">Package</th>
                    <th scope="col" class="px-6 py-3">Server</th>
                    <th scope="col" class="px-6 py-3">Status</th>
                    <th scope="col" class="px-6 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800 text-slate-300">
                @forelse ($accounts as $account)
                    <tr class="hover:bg-slate-800/30 transition">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <a href="{{ route('accounts.show', $account) }}" class="font-semibold text-white hover:text-indigo-400">
                                {{ $account->primary_domain }}
                            </a>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-indigo-400">
                            {{ $account->linux_username }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs">
                            <span class="text-white">{{ $account->user->name }}</span>
                            <span class="text-slate-500 block">{{ $account->user->email }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-slate-300">
                            {{ $account->package->name }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-slate-400">
                            {{ $account->server->name }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @php
                                $color = $account->status->color();
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold
                                @if ($account->status === App\Enums\AccountStatus::ACTIVE) bg-emerald-500/10 text-emerald-400 border border-emerald-500/20
                                @elseif ($account->status === App\Enums\AccountStatus::PENDING) bg-amber-500/10 text-amber-400 border border-amber-500/20
                                @elseif ($account->status === App\Enums\AccountStatus::SUSPENDED) bg-rose-500/10 text-rose-400 border border-rose-500/20
                                @elseif ($account->status === App\Enums\AccountStatus::FAILED) bg-red-500/10 text-red-400 border border-red-500/20
                                @else bg-slate-800 text-slate-400 border border-slate-700
                                @endif">
                                {{ $account->status->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                            <a href="{{ route('accounts.show', $account) }}" class="text-indigo-400 hover:text-indigo-300 font-medium">
                                View Details &rarr;
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-slate-500 text-sm">
                            No hosting accounts found matching your filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($accounts->hasPages())
            <div class="px-6 py-4 border-t border-slate-800">
                {{ $accounts->links() }}
            </div>
        @endif
    </div>
</div>

