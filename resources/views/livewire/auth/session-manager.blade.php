<div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
    <div class="flex items-center justify-between pb-4 border-b border-slate-800">
        <div>
            <h3 class="text-lg font-semibold text-white">Browser Sessions</h3>
            <p class="text-sm text-slate-400 mt-1">Manage and revoke your active sessions across other browsers and devices.</p>
        </div>
    </div>

    <div class="mt-6 space-y-4">
        @if ($sessions->isEmpty())
            <p class="text-sm text-slate-400">No active sessions tracked in database.</p>
        @else
            <div class="divide-y divide-slate-800">
                @foreach ($sessions as $session)
                    <div class="py-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2 rounded-lg bg-slate-800 text-slate-300">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-white">{{ $session['ip_address'] }}</span>
                                    @if ($session['is_current'])
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                            This Device
                                        </span>
                                    @endif
                                </div>
                                <div class="text-xs text-slate-400 mt-0.5 truncate max-w-md">
                                    {{ $session['user_agent'] }}
                                </div>
                                <div class="text-[11px] text-slate-500 mt-0.5">
                                    Last active: {{ $session['last_active'] }}
                                </div>
                            </div>
                        </div>

                        <div>
                            @if (! $session['is_current'])
                                <button type="button" wire:click="revokeSession('{{ $session['id'] }}')"
                                    class="text-xs text-rose-400 hover:text-rose-300 transition">
                                    Revoke
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="pt-4 border-t border-slate-800 flex items-center gap-4">
                @if ($confirmingRevokeAll)
                    <form wire:submit="revokeOtherSessions" class="flex items-center gap-3">
                        <input type="password" wire:model="password" placeholder="Confirm current password"
                            class="rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-1.5 border">
                        <button type="submit"
                            class="px-4 py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-sm font-medium transition">
                            Confirm Revoke Others
                        </button>
                        <button type="button" wire:click="$set('confirmingRevokeAll', false)"
                            class="text-xs text-slate-400 hover:text-white">
                            Cancel
                        </button>
                    </form>
                    @error('password')
                        <span class="text-xs text-rose-400">{{ $message }}</span>
                    @enderror
                @else
                    <button type="button" wire:click="$set('confirmingRevokeAll', true)"
                        class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-lg text-sm font-medium border border-slate-700 transition">
                        Log Out Other Browser Sessions
                    </button>
                @endif
            </div>
        @endif
    </div>
</div>

