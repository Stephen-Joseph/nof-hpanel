<div class="space-y-6">
    <x-layout.page-header 
        title="API Tokens" 
        subtitle="Manage secret keys with fine-grained permissions and IP allow-lists for external integrations."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('api.integration.tokens.create') }}" variant="primary" size="sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Create Token
            </x-ui.button>
            <x-ui.button href="{{ route('api.integration.overview') }}" variant="outline" size="sm">
                &larr; Overview
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    @if (session('status'))
        <div class="p-4 rounded-xl border border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-sm flex items-center justify-between">
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <!-- Filters Toolbar -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="sm:col-span-2">
            <x-ui.input 
                type="text" 
                wire:model.live.debounce.300ms="search" 
                placeholder="Search tokens by name..." 
                prefix-icon="search"
            />
        </div>
        <div>
            <x-ui.select wire:model.live="statusFilter">
                <option value="all">All Statuses</option>
                <option value="active">Active Only</option>
                <option value="revoked">Revoked Only</option>
                <option value="expired">Expired Only</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Tokens Table -->
    <x-ui.card class="p-0 overflow-hidden">
        @if ($tokens->isEmpty())
            <div class="p-8">
                <x-ui.empty-state
                    title="No tokens found"
                    description="{{ $search ? 'No API tokens matched your search query.' : 'Create an API token to begin automating via the REST API.' }}"
                >
                    <x-slot:action>
                        <x-ui.button href="{{ route('api.integration.tokens.create') }}" variant="primary" size="sm">
                            Create First Token
                        </x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-[var(--border)] bg-[var(--bg-shell)] text-[var(--text-muted)] uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="py-3 px-4 font-semibold">Name</th>
                            <th class="py-3 px-4 font-semibold">Prefix</th>
                            <th class="py-3 px-4 font-semibold">Scopes</th>
                            <th class="py-3 px-4 font-semibold">Last Used</th>
                            <th class="py-3 px-4 font-semibold">Expires</th>
                            <th class="py-3 px-4 font-semibold">Status</th>
                            <th class="py-3 px-4 font-semibold text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)] text-[var(--text)]">
                        @foreach ($tokens as $token)
                            @php
                                $isExpired = $token->isExpired();
                                $isRevoked = $token->isRevoked();
                                $isActive = ! $isRevoked && ! $isExpired;
                            @endphp
                            <tr class="hover:bg-[var(--bg-shell)] transition-colors">
                                <td class="py-3.5 px-4">
                                    <div class="font-semibold text-sm text-[var(--text)]">{{ $token->name }}</div>
                                    @if (auth()->user()->isAdmin() && $token->user)
                                        <div class="text-[11px] text-[var(--text-muted)]">Owner: {{ $token->user->email }}</div>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 font-mono text-[var(--text-muted)]">
                                    <span class="px-2 py-0.5 rounded bg-[var(--bg-shell)] border border-[var(--border)]">
                                        {{ $token->prefix ?? substr($token->token_hash, 0, 8) }}...
                                    </span>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="flex flex-wrap gap-1 max-w-xs">
                                        @if (empty($token->scopes) || in_array('*', $token->scopes, true))
                                            <x-ui.chip variant="primary">Full Access (*)</x-ui.chip>
                                        @else
                                            @foreach ($token->scopes as $scope)
                                                <x-ui.chip variant="neutral">{{ $scope }}</x-ui.chip>
                                            @endforeach
                                        @endif
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 text-[var(--text-muted)]">
                                    {{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'Never' }}
                                </td>
                                <td class="py-3.5 px-4 text-[var(--text-muted)]">
                                    {{ $token->expires_at ? $token->expires_at->format('M d, Y') : 'Never' }}
                                </td>
                                <td class="py-3.5 px-4">
                                    @if ($isRevoked)
                                        <x-ui.chip variant="danger">Revoked</x-ui.chip>
                                    @elseif ($isExpired)
                                        <x-ui.chip variant="warning">Expired</x-ui.chip>
                                    @else
                                        <x-ui.chip variant="success">Active</x-ui.chip>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-right">
                                    <div class="inline-flex items-center gap-1.5 justify-end">
                                        @if ($isActive)
                                            <x-ui.button 
                                                type="button" 
                                                variant="ghost" 
                                                size="xs" 
                                                wire:click="openEditScopes({{ $token->id }})"
                                                title="Edit Scopes"
                                            >
                                                Scopes
                                            </x-ui.button>

                                            <x-ui.button 
                                                type="button" 
                                                variant="ghost" 
                                                size="xs" 
                                                wire:click="openEditIps({{ $token->id }})"
                                                title="Edit IP Allowlist"
                                            >
                                                IPs
                                            </x-ui.button>

                                            <x-ui.button 
                                                type="button" 
                                                variant="ghost" 
                                                size="xs" 
                                                wire:click="rotateToken({{ $token->id }})"
                                                wire:confirm="Are you sure you want to rotate this token? The existing token secret will immediately become invalid."
                                                title="Rotate Secret"
                                            >
                                                Rotate
                                            </x-ui.button>

                                            <x-ui.button 
                                                type="button" 
                                                variant="ghost" 
                                                size="xs" 
                                                class="text-rose-500 hover:text-rose-600 hover:bg-rose-500/10"
                                                wire:click="confirmRevoke({{ $token->id }})"
                                                title="Revoke Token"
                                            >
                                                Revoke
                                            </x-ui.button>
                                        @else
                                            <span class="text-xs text-[var(--text-muted)] italic">Inactive</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-[var(--border)]">
                {{ $tokens->links() }}
            </div>
        @endif
    </x-ui.card>

    <!-- Modal: Edit Scopes -->
    <x-ui.modal name="edit-scopes-modal" max-width="lg">
        <div class="p-6 space-y-4">
            <h3 class="text-base font-semibold text-[var(--text)] border-b border-[var(--border)] pb-3">Edit Token Scopes</h3>
            
            <p class="text-xs text-[var(--text-muted)]">Select authorized permissions for this token:</p>

            <div class="space-y-2.5 max-h-72 overflow-y-auto pr-1">
                @foreach ($availableScopes as $scopeKey => $scopeDesc)
                    <label class="flex items-start gap-3 p-2.5 rounded-xl border border-[var(--border)] hover:bg-[var(--bg-shell)] cursor-pointer transition-colors">
                        <input 
                            type="checkbox" 
                            wire:model="editingScopes" 
                            value="{{ $scopeKey }}"
                            class="mt-0.5 rounded border-[var(--border)] text-blue-600 focus:ring-blue-500"
                        />
                        <div class="text-xs">
                            <span class="font-mono font-bold text-[var(--text)]">{{ $scopeKey }}</span>
                            <p class="text-[var(--text-muted)] text-[11px] mt-0.5">{{ $scopeDesc }}</p>
                        </div>
                    </label>
                @endforeach
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-[var(--border)]">
                <x-ui.button type="button" variant="ghost" size="sm" x-on:click="$dispatch('close-modal', 'edit-scopes-modal')">
                    Cancel
                </x-ui.button>
                <x-ui.button type="button" variant="primary" size="sm" wire:click="saveScopes">
                    Save Scopes
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Modal: Edit IP Allowlist -->
    <x-ui.modal name="edit-ips-modal" max-width="md">
        <div class="p-6 space-y-4">
            <h3 class="text-base font-semibold text-[var(--text)] border-b border-[var(--border)] pb-3">Edit IP Allow-list</h3>
            
            <p class="text-xs text-[var(--text-muted)]">
                Enter allowed IP addresses or CIDR blocks (one per line). Leave empty to allow requests from any IP.
            </p>

            <div>
                <textarea 
                    wire:model="editingIpAllowlist" 
                    rows="5" 
                    placeholder="192.168.1.100&#10;10.0.0.0/24"
                    class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] p-3 font-mono text-xs text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-blue-500"
                ></textarea>
                @error('editingIpAllowlist') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-[var(--border)]">
                <x-ui.button type="button" variant="ghost" size="sm" x-on:click="$dispatch('close-modal', 'edit-ips-modal')">
                    Cancel
                </x-ui.button>
                <x-ui.button type="button" variant="primary" size="sm" wire:click="saveIps">
                    Save IPs
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Modal: Confirm Revoke -->
    <x-ui.modal name="revoke-token-modal" max-width="sm">
        <div class="p-6 space-y-4">
            <div class="flex items-center gap-3 text-rose-500">
                <div class="w-10 h-10 rounded-full bg-rose-500/10 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-[var(--text)]">Revoke Token</h3>
                    <p class="text-xs text-[var(--text-muted)]">This action cannot be undone.</p>
                </div>
            </div>

            <p class="text-xs text-[var(--text-muted)]">
                Any external script or integration using this token will immediately receive a 401 Unauthorized response.
            </p>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-[var(--border)]">
                <x-ui.button type="button" variant="ghost" size="sm" x-on:click="$dispatch('close-modal', 'revoke-token-modal')">
                    Cancel
                </x-ui.button>
                <x-ui.button type="button" variant="danger" size="sm" wire:click="revokeToken">
                    Yes, Revoke Token
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Modal: Rotated Plain Token Display -->
    <x-ui.modal name="rotated-token-modal" max-width="md">
        <div class="p-6 space-y-4" x-data="{ copied: false }">
            <div class="flex items-center gap-3 text-emerald-500">
                <div class="w-10 h-10 rounded-full bg-emerald-500/10 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-[var(--text)]">Token Rotated</h3>
                    <p class="text-xs text-[var(--text-muted)]">Copy your new secret key now.</p>
                </div>
            </div>

            <div class="p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-400 text-xs">
                <strong>Warning:</strong> This secret key will only be shown once. If you lose it, you will need to rotate it again.
            </div>

            <div class="relative">
                <div class="p-3 rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] font-mono text-xs text-[var(--text)] break-all select-all">
                    {{ $rotatedPlainTextToken }}
                </div>
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-[var(--border)]">
                <x-ui.button 
                    type="button" 
                    variant="outline" 
                    size="sm"
                    @click="navigator.clipboard.writeText('{{ $rotatedPlainTextToken }}'); copied = true; setTimeout(() => copied = false, 2000)"
                >
                    <svg x-show="!copied" class="w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    <svg x-show="copied" x-cloak class="w-3.5 h-3.5 mr-1.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <span x-text="copied ? 'Copied!' : 'Copy Secret'"></span>
                </x-ui.button>

                <x-ui.button type="button" variant="primary" size="sm" x-on:click="$dispatch('close-modal', 'rotated-token-modal')">
                    Done
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>

