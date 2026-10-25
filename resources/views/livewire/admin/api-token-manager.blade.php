<div>
    <x-layout.page-header 
        title="API Tokens &amp; Webhooks" 
        subtitle="Manage external billing API access, scoped Bearer tokens, and webhook deliveries."
    />

    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    <!-- Generated Plaintext Token Alert -->
    @if ($generatedPlainTextToken)
        <div class="mb-6 p-4 rounded-[20px] bg-primary-500/10 border border-primary-500/30 text-primary-500">
            <h4 class="text-sm font-bold text-main mb-1">New API Token Generated</h4>
            <p class="text-xs text-muted mb-3">Copy this token now. For security reasons, you will never be able to see it again.</p>
            <div class="flex items-center gap-2">
                <input type="text" readonly value="{{ $generatedPlainTextToken }}" class="font-mono text-xs w-full px-3.5 py-2 bg-card border border-primary-500/40 rounded-full text-main select-all outline-hidden">
                <x-ui.button type="button" wire:click="$set('generatedPlainTextToken', null)" variant="outline" size="sm">
                    Dismiss
                </x-ui.button>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Create Token Form -->
        <x-ui.card class="lg:col-span-1">
            <h3 class="text-base font-semibold text-main mb-4">Create API Token</h3>
            <form wire:submit="createToken" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Token Name / Client</label>
                    <x-ui.input type="text" wire:model="tokenName" placeholder="e.g. WHMCS Production" />
                    @error('tokenName') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Scopes (comma-separated)</label>
                    <x-ui.input type="text" wire:model="selectedScopes" placeholder="* or accounts:read,accounts:write" class="font-mono text-xs" />
                    @error('selectedScopes') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">IP Allowlist (optional, comma-separated)</label>
                    <x-ui.input type="text" wire:model="ipAllowlist" placeholder="e.g. 192.168.1.100, 10.0.0.0/24" class="font-mono text-xs" />
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Expires In (Days)</label>
                    <x-ui.input type="number" wire:model="expiresInDays" />
                </div>

                <x-ui.button type="submit" variant="primary" class="w-full">
                    Generate API Token
                </x-ui.button>
            </form>
        </x-ui.card>

        <!-- Tokens List -->
        <x-ui.card class="p-0 overflow-hidden lg:col-span-2 flex flex-col">
            <div class="px-6 py-4 border-b border-border">
                <h3 class="text-base font-semibold text-main">Active API Tokens</h3>
            </div>
            <div class="overflow-x-auto flex-1">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                            <th class="py-3 px-6">Name</th>
                            <th class="py-3 px-6">Owner</th>
                            <th class="py-3 px-6">Scopes</th>
                            <th class="py-3 px-6">Last Used</th>
                            <th class="py-3 px-6">Status</th>
                            <th class="py-3 px-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-main">
                        @forelse ($tokens as $token)
                            <tr class="hover:bg-shell/40 transition">
                                <td class="px-6 py-3.5">
                                    <div class="font-bold text-main">{{ $token->name }}</div>
                                    <div class="font-mono text-[10px] text-muted">{{ substr($token->token_hash, 0, 12) }}...</div>
                                </td>
                                <td class="px-6 py-3.5">
                                    <div class="font-medium text-main">{{ $token->user?->name ?? 'System' }}</div>
                                    <div class="text-[10px] text-muted">{{ $token->user?->email ?? '—' }}</div>
                                </td>
                                <td class="px-6 py-3.5 font-mono text-muted">
                                    {{ implode(', ', $token->scopes ?? ['*']) }}
                                </td>
                                <td class="px-6 py-3.5 text-muted">
                                    {{ $token->last_used_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="px-6 py-3.5">
                                    @if ($token->isRevoked())
                                        <x-ui.chip variant="danger" size="sm">Revoked</x-ui.chip>
                                    @elseif ($token->isExpired())
                                        <x-ui.chip variant="warning" size="sm">Expired</x-ui.chip>
                                    @else
                                        <x-ui.chip variant="success" size="sm">Active</x-ui.chip>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    @if (! $token->isRevoked())
                                        <button wire:click="confirmRevoke({{ $token->id }})"
                                            class="text-rose-500 hover:text-rose-600 font-medium cursor-pointer">
                                            Revoke
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-6 text-center text-muted text-xs">No API tokens configured.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>

    <!-- Webhooks Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Register Webhook -->
        <x-ui.card class="lg:col-span-1">
            <h3 class="text-base font-semibold text-main mb-4">Register Webhook Endpoint</h3>
            <form wire:submit="createWebhook" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Payload URL</label>
                    <x-ui.input type="url" wire:model="webhookUrl" placeholder="https://billing.example.com/webhooks" />
                    @error('webhookUrl') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Subscribed Events</label>
                    <x-ui.input type="text" wire:model="webhookEvents" class="font-mono text-xs" />
                    <p class="text-[10px] text-muted mt-1.5">e.g. account.created, account.suspended, account.terminated, ssl.issued</p>
                </div>

                <x-ui.button type="submit" variant="primary" class="w-full">
                    Subscribe Webhook
                </x-ui.button>
            </form>
        </x-ui.card>

        <!-- Deliveries Log -->
        <x-ui.card class="p-0 overflow-hidden lg:col-span-2">
            <div class="px-6 py-4 border-b border-border">
                <h3 class="text-base font-semibold text-main">Recent Webhook Deliveries</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                            <th class="py-3 px-6">Event</th>
                            <th class="py-3 px-6">Endpoint URL</th>
                            <th class="py-3 px-6">Status</th>
                            <th class="py-3 px-6">Attempts</th>
                            <th class="py-3 px-6">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-main font-mono">
                        @forelse ($deliveries as $deliv)
                            <tr class="hover:bg-shell/40 transition">
                                <td class="px-6 py-3.5 font-semibold text-primary-500">{{ $deliv->event }}</td>
                                <td class="px-6 py-3.5 truncate max-w-xs text-muted">{{ $deliv->subscription->url }}</td>
                                <td class="px-6 py-3.5 font-sans">
                                    @if ($deliv->delivered_at)
                                        <x-ui.chip variant="success" size="sm">
                                            HTTP {{ $deliv->status_code ?? 200 }}
                                        </x-ui.chip>
                                    @else
                                        <x-ui.chip variant="danger" size="sm">
                                            {{ $deliv->error ?? 'Failed' }}
                                        </x-ui.chip>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 text-muted">{{ $deliv->attempts }}</td>
                                <td class="px-6 py-3.5 text-muted font-sans">{{ $deliv->created_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-6 text-center text-muted font-sans text-xs">No webhook deliveries recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>

    <!-- Revoke Token Confirmation Modal -->
    <x-ui.modal name="revoke-admin-token-modal" max-width="sm">
        <div class="p-6 space-y-4">
            <div class="flex items-center gap-3 text-rose-500">
                <div class="w-10 h-10 rounded-full bg-rose-500/10 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-main">Revoke API Token</h3>
                    <p class="text-xs text-muted">This action cannot be undone.</p>
                </div>
            </div>

            <p class="text-xs text-muted">
                Any external billing or orchestration integration using this token will immediately receive a 401 Unauthorized response.
            </p>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-border">
                <x-ui.button type="button" variant="ghost" size="sm" x-on:click="$dispatch('close-modal', 'revoke-admin-token-modal')">
                    Cancel
                </x-ui.button>
                <x-ui.button type="button" variant="danger" size="sm" wire:click="revokeToken">
                    Yes, Revoke Token
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
