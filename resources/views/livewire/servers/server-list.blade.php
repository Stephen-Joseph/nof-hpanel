<div>
    <x-layout.page-header 
        title="Server Nodes" 
        subtitle="Linux servers running the Go agent daemon over private WireGuard networks."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button type="button" wire:click="openEnrollModal" variant="outline" size="sm">
                    ⚡ Enroll Node via Token
                </x-ui.button>
                <x-ui.button href="{{ route('servers.create') }}" variant="primary" size="sm">
                    + Manual Add Node
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.card class="p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="py-3 px-6">Server Name &amp; Hostname</th>
                        <th class="py-3 px-6">Private IP / Agent URL</th>
                        <th class="py-3 px-6">Hardware Spec</th>
                        <th class="py-3 px-6">Capacity &amp; Load</th>
                        <th class="py-3 px-6">Status</th>
                        <th class="py-3 px-6 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-main">
                    @forelse ($servers as $srv)
                        <tr class="hover:bg-shell/40 transition">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-semibold text-main">{{ $srv->name }}</span>
                                <span class="block text-xs font-mono text-muted">{{ $srv->hostname }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs font-mono">
                                <span class="text-main font-medium">{{ $srv->private_ip }}</span>
                                <span class="block text-muted text-[11px]">{{ $srv->agent_url }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs text-muted">
                                {{ $srv->cpu_cores ?? '?' }} Cores &middot;
                                {{ round(($srv->memory_mb ?? 0) / 1024, 1) }} GB RAM &middot;
                                {{ round(($srv->storage_mb ?? 0) / 1024, 1) }} GB Disk
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs font-mono">
                                <span class="text-main font-semibold">{{ $srv->accounts_count }}</span> / {{ $srv->max_accounts }} accounts
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <x-ui.chip :variant="match($srv->status) {
                                    App\Enums\ServerStatus::ACTIVE => 'success',
                                    App\Enums\ServerStatus::DRAINING => 'info',
                                    App\Enums\ServerStatus::MAINTENANCE => 'warning',
                                    default => 'danger',
                                }" size="sm">
                                    {{ $srv->status->label() }}
                                </x-ui.chip>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                                <div class="flex items-center justify-end gap-3">
                                    <button type="button" wire:click="toggleDrain({{ $srv->id }})"
                                        class="text-xs font-medium cursor-pointer {{ $srv->status === App\Enums\ServerStatus::ACTIVE ? 'text-amber-500 hover:text-amber-600' : 'text-emerald-500 hover:text-emerald-600' }}">
                                        {{ $srv->status === App\Enums\ServerStatus::ACTIVE ? 'Drain' : 'Undrain' }}
                                    </button>

                                    <a href="{{ route('servers.edit', $srv) }}" class="text-primary-500 hover:text-primary-600 font-medium">
                                        Edit
                                    </a>

                                    @if ($srv->accounts_count === 0)
                                        <button type="button" wire:click="deleteServer({{ $srv->id }})" wire:confirm="Are you sure you want to remove this server node?"
                                            class="text-rose-500 hover:text-rose-600 font-medium cursor-pointer">
                                            Delete
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-muted text-xs">
                                No server nodes registered.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <!-- Node Enrollment Modal -->
    @if ($showEnrollModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-lg w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-main mb-1">Enroll Server Node</h3>
                <p class="text-xs text-muted mb-4">Generate a 24-hour one-time enrollment token to automatically configure the Go agent on a target Ubuntu/Debian server.</p>

                @if ($generatedToken)
                    <div class="space-y-4">
                        <div class="p-3.5 bg-emerald-500/10 border border-emerald-500/20 rounded-[14px] text-xs text-emerald-600 dark:text-emerald-400">
                            Token generated successfully! Run the command below on your remote server terminal as root.
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">One-Line Automated Setup Command</label>
                            <textarea readonly rows="3" class="block w-full px-3.5 py-2.5 rounded-[14px] bg-shell border border-border text-primary-500 font-mono text-[11px] select-all outline-hidden">{{ $enrollInstallCommand }}</textarea>
                        </div>

                        <div class="flex justify-end pt-2">
                            <x-ui.button type="button" wire:click="$set('showEnrollModal', false)" variant="primary" size="sm">Done</x-ui.button>
                        </div>
                    </div>
                @else
                    <form wire:submit.prevent="generateEnrollToken" class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Server Friendly Name</label>
                            <x-ui.input type="text" wire:model="enrollServerName" placeholder="e.g. EU-Node-02" />
                            @error('enrollServerName') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Server Cluster / Group</label>
                            <x-ui.select wire:model="enrollServerGroupId">
                                <option value="">Default Cluster</option>
                                @foreach ($serverGroups as $group)
                                    <option value="{{ $group->id }}">{{ $group->name }} ({{ $group->location }})</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                            <x-ui.button type="button" wire:click="$set('showEnrollModal', false)" variant="ghost" size="sm">Cancel</x-ui.button>
                            <x-ui.button type="submit" variant="primary" size="sm">Generate Enrollment Token</x-ui.button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
