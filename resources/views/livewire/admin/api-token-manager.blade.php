<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">API Tokens &amp; Webhooks</h2>
            <p class="text-sm text-slate-400 mt-1">Manage external billing API access, scoped Bearer tokens, and webhook deliveries.</p>
        </div>
    </div>

    @if (session()->has('status'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    <!-- Generated Plaintext Token Alert -->
    @if ($generatedPlainTextToken)
        <div class="mb-6 p-4 rounded-xl bg-indigo-500/10 border border-indigo-500/30 text-indigo-300">
            <h4 class="text-sm font-bold text-white mb-1">New API Token Generated</h4>
            <p class="text-xs text-indigo-300/80 mb-3">Copy this token now. For security reasons, you will never be able to see it again.</p>
            <div class="flex items-center gap-2">
                <input type="text" readonly value="{{ $generatedPlainTextToken }}" class="font-mono text-xs w-full p-2.5 bg-slate-900 border border-indigo-500/40 rounded-lg text-white select-all">
                <button type="button" wire:click="$set('generatedPlainTextToken', null)" class="px-3 py-2 text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg border border-slate-700">
                    Dismiss
                </button>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Create Token Form -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 lg:col-span-1">
            <h3 class="text-base font-semibold text-white mb-4">Create API Token</h3>
            <form wire:submit="createToken" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Token Name / Client</label>
                    <input type="text" wire:model="tokenName" placeholder="e.g. WHMCS Production"
                        class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                    @error('tokenName') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Scopes (comma-separated)</label>
                    <input type="text" wire:model="selectedScopes" placeholder="* or accounts:read,accounts:write"
                        class="w-full rounded-lg bg-slate-800 border-slate-700 text-white font-mono text-xs px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                    @error('selectedScopes') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">IP Allowlist (optional, comma-separated)</label>
                    <input type="text" wire:model="ipAllowlist" placeholder="e.g. 192.168.1.100, 10.0.0.0/24"
                        class="w-full rounded-lg bg-slate-800 border-slate-700 text-white font-mono text-xs px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Expires In (Days)</label>
                    <input type="number" wire:model="expiresInDays"
                        class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                </div>

                <button type="submit" class="w-full py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium rounded-lg shadow transition">
                    Generate API Token
                </button>
            </form>
        </div>

        <!-- Tokens List -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm lg:col-span-2 flex flex-col">
            <div class="px-6 py-4 border-b border-slate-800">
                <h3 class="text-base font-semibold text-white">Active API Tokens</h3>
            </div>
            <div class="overflow-x-auto flex-1">
                <table class="min-w-full divide-y divide-slate-800 text-left text-xs">
                    <thead class="bg-slate-800/50 text-slate-400 uppercase font-semibold">
                        <tr>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Scopes</th>
                            <th class="px-4 py-3">Last Used</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-300">
                        @forelse ($tokens as $token)
                            <tr class="hover:bg-slate-800/30 transition">
                                <td class="px-4 py-3">
                                    <div class="font-bold text-white">{{ $token->name }}</div>
                                    <div class="font-mono text-[10px] text-slate-500">{{ substr($token->token_hash, 0, 12) }}...</div>
                                </td>
                                <td class="px-4 py-3 font-mono text-slate-400">
                                    {{ implode(', ', $token->scopes ?? ['*']) }}
                                </td>
                                <td class="px-4 py-3 text-slate-400">
                                    {{ $token->last_used_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="px-4 py-3">
                                    @if ($token->isRevoked())
                                        <span class="px-2 py-0.5 rounded text-[10px] bg-rose-500/10 text-rose-400 border border-rose-500/20">Revoked</span>
                                    @elseif ($token->isExpired())
                                        <span class="px-2 py-0.5 rounded text-[10px] bg-amber-500/10 text-amber-400 border border-amber-500/20">Expired</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded text-[10px] bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Active</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if (! $token->isRevoked())
                                        <button wire:click="revokeToken({{ $token->id }})" wire:confirm="Are you sure you want to revoke this API token?"
                                            class="text-rose-400 hover:text-rose-300 font-medium">
                                            Revoke
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500">No API tokens configured.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Webhooks Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Register Webhook -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 lg:col-span-1">
            <h3 class="text-base font-semibold text-white mb-4">Register Webhook Endpoint</h3>
            <form wire:submit="createWebhook" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Payload URL</label>
                    <input type="url" wire:model="webhookUrl" placeholder="https://billing.example.com/webhooks"
                        class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                    @error('webhookUrl') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Subscribed Events</label>
                    <input type="text" wire:model="webhookEvents"
                        class="w-full rounded-lg bg-slate-800 border-slate-700 text-white font-mono text-xs px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                    <p class="text-[10px] text-slate-500 mt-1">e.g. account.created, account.suspended, account.terminated, ssl.issued</p>
                </div>

                <button type="submit" class="w-full py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium rounded-lg shadow transition">
                    Subscribe Webhook
                </button>
            </form>
        </div>

        <!-- Deliveries Log -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm lg:col-span-2">
            <div class="px-6 py-4 border-b border-slate-800">
                <h3 class="text-base font-semibold text-white">Recent Webhook Deliveries</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800 text-left text-xs">
                    <thead class="bg-slate-800/50 text-slate-400 uppercase font-semibold">
                        <tr>
                            <th class="px-4 py-3">Event</th>
                            <th class="px-4 py-3">Endpoint URL</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Attempts</th>
                            <th class="px-4 py-3">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-300 font-mono">
                        @forelse ($deliveries as $deliv)
                            <tr class="hover:bg-slate-800/30 transition">
                                <td class="px-4 py-3 font-semibold text-indigo-400">{{ $deliv->event }}</td>
                                <td class="px-4 py-3 truncate max-w-xs text-slate-400">{{ $deliv->subscription->url }}</td>
                                <td class="px-4 py-3 font-sans">
                                    @if ($deliv->delivered_at)
                                        <span class="px-2 py-0.5 rounded text-[10px] bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                            HTTP {{ $deliv->status_code ?? 200 }}
                                        </span>
                                    @else
                                        <span class="px-2 py-0.5 rounded text-[10px] bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                            {{ $deliv->error ?? 'Failed' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-slate-400">{{ $deliv->attempts }}</td>
                                <td class="px-4 py-3 text-slate-500 font-sans">{{ $deliv->created_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500 font-sans">No webhook deliveries recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

