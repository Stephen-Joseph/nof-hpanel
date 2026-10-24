<x-ui.card>
    <div class="flex items-center justify-between pb-4 border-b border-border">
        <div>
            <h3 class="text-base font-semibold text-main">Browser Sessions</h3>
            <p class="text-xs text-muted mt-1">Manage and revoke your active sessions across other browsers and devices.</p>
        </div>
    </div>

    <div class="mt-6 space-y-4">
        @if ($sessions->isEmpty())
            <p class="text-xs text-muted">No active sessions tracked in database.</p>
        @else
            <div class="divide-y divide-border">
                @foreach ($sessions as $session)
                    <div class="py-3.5 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-full bg-shell border border-border text-primary-500">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-main">{{ $session['ip_address'] }}</span>
                                    @if ($session['is_current'])
                                        <x-ui.chip variant="success" size="sm">
                                            This Device
                                        </x-ui.chip>
                                    @endif
                                </div>
                                <div class="text-[11px] text-muted mt-0.5 truncate max-w-md">
                                    {{ $session['user_agent'] }}
                                </div>
                                <div class="text-[10px] text-muted/70 mt-0.5 font-mono">
                                    Last active: {{ $session['last_active'] }}
                                </div>
                            </div>
                        </div>

                        <div>
                            @if (! $session['is_current'])
                                <button type="button" wire:click="revokeSession('{{ $session['id'] }}')"
                                    class="text-xs text-rose-500 hover:text-rose-600 transition cursor-pointer font-medium">
                                    Revoke
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="pt-4 border-t border-border flex items-center gap-4">
                @if ($confirmingRevokeAll)
                    <form wire:submit="revokeOtherSessions" class="flex items-center gap-3">
                        <x-ui.input type="password" wire:model="password" placeholder="Confirm current password" />
                        <x-ui.button type="submit" variant="danger" size="sm">
                            Confirm Revoke Others
                        </x-ui.button>
                        <x-ui.button type="button" wire:click="$set('confirmingRevokeAll', false)" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                    </form>
                    @error('password')
                        <span class="text-xs text-rose-500">{{ $message }}</span>
                    @enderror
                @else
                    <x-ui.button type="button" wire:click="$set('confirmingRevokeAll', true)" variant="outline" size="sm">
                        Log Out Other Browser Sessions
                    </x-ui.button>
                @endif
            </div>
        @endif
    </div>
</x-ui.card>
