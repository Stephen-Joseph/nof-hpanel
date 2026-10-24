<div>
    <x-layout.page-header 
        title="Hosting Accounts" 
        subtitle="Isolated Linux accounts, PHP pools, and vhost environments."
    >
        @if ($canCreate)
            <x-slot:actions>
                <x-ui.button href="{{ route('accounts.create') }}" variant="primary" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New Account
                </x-ui.button>
            </x-slot:actions>
        @endif
    </x-layout.page-header>

    <!-- Filters Toolbar (Inline Toolbar) -->
    <div class="mb-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs font-medium text-muted mb-1.5">Search Domain / Username / User</label>
            <x-ui.input type="text" wire:model.live.debounce.300ms="search" placeholder="Filter accounts..." />
        </div>

        <div>
            <label class="block text-xs font-medium text-muted mb-1.5">Status</label>
            <x-ui.select wire:model.live="status">
                <option value="">All Statuses</option>
                @foreach ($statuses as $st)
                    <option value="{{ $st->value }}">{{ $st->label() }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div>
            <label class="block text-xs font-medium text-muted mb-1.5">Server</label>
            <x-ui.select wire:model.live="serverId">
                <option value="">All Servers</option>
                @foreach ($servers as $srv)
                    <option value="{{ $srv->id }}">{{ $srv->name }} ({{ $srv->hostname }})</option>
                @endforeach
            </x-ui.select>
        </div>
    </div>

    <!-- Table -->
    <x-ui.card class="p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="py-3 px-6">Primary Domain</th>
                        <th class="py-3 px-6">Linux User</th>
                        <th class="py-3 px-6">Owner</th>
                        <th class="py-3 px-6">Package</th>
                        <th class="py-3 px-6">Server</th>
                        <th class="py-3 px-6">Status</th>
                        <th class="py-3 px-6 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-main">
                    @forelse ($accounts as $account)
                        <tr class="hover:bg-shell/40 transition">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <a href="{{ route('accounts.show', $account) }}" class="font-semibold text-main hover:text-primary-500">
                                    {{ $account->primary_domain }}
                                </a>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap font-mono text-primary-500 font-medium">
                                {{ $account->linux_username }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-medium text-main">{{ $account->user->name }}</span>
                                <span class="text-muted block text-[11px]">{{ $account->user->email }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-muted">
                                {{ $account->package->name }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-muted">
                                {{ $account->server->name }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <x-ui.chip :variant="match($account->status) {
                                    App\Enums\AccountStatus::ACTIVE => 'success',
                                    App\Enums\AccountStatus::PENDING => 'warning',
                                    App\Enums\AccountStatus::SUSPENDED, App\Enums\AccountStatus::FAILED => 'danger',
                                    default => 'default',
                                }" size="sm">
                                    {{ $account->status->label() }}
                                </x-ui.chip>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <a href="{{ route('accounts.show', $account) }}" class="text-primary-500 hover:text-primary-600 font-medium inline-flex items-center gap-1">
                                    View Details &rarr;
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-muted text-xs">
                                No hosting accounts found matching your filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($accounts->hasPages())
            <div class="px-6 py-4 border-t border-border">
                {{ $accounts->links() }}
            </div>
        @endif
    </x-ui.card>
</div>
