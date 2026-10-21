<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">Server Nodes</h2>
            <p class="text-sm text-slate-400 mt-1">Linux servers running the Go agent daemon over private WireGuard networks.</p>
        </div>
        <div>
            <a href="{{ route('servers.create') }}"
                class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 transition">
                Add Server Node
            </a>
        </div>
    </div>

    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                <tr>
                    <th scope="col" class="px-6 py-3">Server Name &amp; Hostname</th>
                    <th scope="col" class="px-6 py-3">Private IP / Agent URL</th>
                    <th scope="col" class="px-6 py-3">Hardware Spec</th>
                    <th scope="col" class="px-6 py-3">Capacity &amp; Load</th>
                    <th scope="col" class="px-6 py-3">Status</th>
                    <th scope="col" class="px-6 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800 text-slate-300">
                @forelse ($servers as $srv)
                    <tr class="hover:bg-slate-800/30 transition">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="font-semibold text-white">{{ $srv->name }}</span>
                            <span class="block text-xs font-mono text-slate-400">{{ $srv->hostname }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs font-mono">
                            <span class="text-white">{{ $srv->private_ip }}</span>
                            <span class="block text-slate-500">{{ $srv->agent_url }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-slate-400">
                            {{ $srv->cpu_cores ?? '?' }} Cores &middot;
                            {{ round(($srv->memory_mb ?? 0) / 1024, 1) }} GB RAM &middot;
                            {{ round(($srv->storage_mb ?? 0) / 1024, 1) }} GB Disk
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs font-mono">
                            <span class="text-white font-semibold">{{ $srv->accounts_count }}</span> / {{ $srv->max_accounts }} accounts
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold
                                @if ($srv->status === App\Enums\ServerStatus::ACTIVE) bg-emerald-500/10 text-emerald-400 border border-emerald-500/20
                                @elseif ($srv->status === App\Enums\ServerStatus::DRAINING) bg-indigo-500/10 text-indigo-400 border border-indigo-500/20
                                @elseif ($srv->status === App\Enums\ServerStatus::MAINTENANCE) bg-amber-500/10 text-amber-400 border border-amber-500/20
                                @else bg-rose-500/10 text-rose-400 border border-rose-500/20
                                @endif">
                                {{ $srv->status->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('servers.edit', $srv) }}" class="text-indigo-400 hover:text-indigo-300 font-medium">
                                    Edit
                                </a>

                                @if ($srv->accounts_count === 0)
                                    <button type="button" wire:click="deleteServer({{ $srv->id }})" wire:confirm="Are you sure you want to remove this server node?"
                                        class="text-rose-400 hover:text-rose-300 font-medium">
                                        Delete
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-slate-500 text-sm">
                            No server nodes registered.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

