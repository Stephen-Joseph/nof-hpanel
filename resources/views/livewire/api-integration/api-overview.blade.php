<div class="space-y-6">
    <x-layout.page-header 
        title="API Integration" 
        subtitle="Manage access tokens, webhooks, inspect live API request telemetry, and view documentation."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('api.integration.tokens.create') }}" variant="primary" size="sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Generate Token
            </x-ui.button>
            <x-ui.button href="{{ route('api.integration.docs') }}" variant="outline" size="sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                </svg>
                API Docs
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Stat Tiles (4 stats) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
        <x-ui.stat-tile
            title="Active Tokens"
            :value="$activeTokensCount"
            trend-label="Scoped API Keys"
            :highlighted="true"
            :href="route('api.integration.tokens')"
        />

        <x-ui.stat-tile
            title="Requests (24h)"
            :value="$totalRequests24h"
            trend-label="Telemetry window"
            :href="route('api.integration.logs')"
        />

        <x-ui.stat-tile
            title="Failed Requests (24h)"
            :value="$failedRequests24h"
            trend-label="4xx and 5xx errors"
            :href="route('api.integration.logs')"
        />

        <x-ui.stat-tile
            title="Failed Deliveries"
            :value="$failedWebhooksCount"
            trend-label="Webhook retries"
            :href="route('api.integration.webhooks')"
        />
    </div>

    <!-- Base URL & Quick Links Card -->
    <x-ui.card>
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="space-y-1">
                <h3 class="text-sm font-semibold text-[var(--text)]">Base Endpoint URL</h3>
                <p class="text-xs text-[var(--text-muted)]">Prefix for all REST endpoints. Send requests with HTTP Bearer authorization.</p>
            </div>

            <div class="flex items-center gap-2" x-data="{ copied: false }">
                <code class="px-3.5 py-2 rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] font-mono text-xs text-[var(--text)] select-all">
                    {{ $baseUrl }}
                </code>
                <x-ui.button 
                    type="button" 
                    variant="outline" 
                    size="sm"
                    @click="navigator.clipboard.writeText('{{ $baseUrl }}'); copied = true; setTimeout(() => copied = false, 2000)"
                >
                    <svg x-show="!copied" class="w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    <svg x-show="copied" x-cloak class="w-3.5 h-3.5 mr-1.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <span x-text="copied ? 'Copied' : 'Copy URL'"></span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.card>

    <!-- Recent Requests Table (Last 10) -->
    <x-ui.card class="space-y-4">
        <div class="flex items-center justify-between border-b border-[var(--border)] pb-3">
            <div>
                <h3 class="text-sm font-semibold text-[var(--text)]">Recent API Telemetry</h3>
                <p class="text-xs text-[var(--text-muted)]">Latest 10 HTTP requests processed by the API gateway.</p>
            </div>
            <x-ui.button href="{{ route('api.integration.logs') }}" variant="ghost" size="xs">
                View All Logs &rarr;
            </x-ui.button>
        </div>

        @if ($recentRequests->isEmpty())
            <x-ui.empty-state
                title="No API requests yet"
                description="Make requests to /api/v1 endpoints with your token to see live telemetry."
            />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="border-b border-[var(--border)] text-[var(--text-muted)] uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="py-2.5 px-3">Method</th>
                            <th class="py-2.5 px-3">Path</th>
                            <th class="py-2.5 px-3">Status</th>
                            <th class="py-2.5 px-3">Token</th>
                            <th class="py-2.5 px-3">Latency</th>
                            <th class="py-2.5 px-3 text-right">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)] text-[var(--text)]">
                        @foreach ($recentRequests as $req)
                            <tr class="hover:bg-[var(--bg-shell)] transition-colors">
                                <td class="py-2.5 px-3">
                                    <x-ui.chip :variant="match($req->method) {
                                        'GET' => 'success',
                                        'POST' => 'primary',
                                        'DELETE' => 'danger',
                                        default => 'neutral'
                                    }">
                                        {{ $req->method }}
                                    </x-ui.chip>
                                </td>
                                <td class="py-2.5 px-3 text-xs font-medium font-mono text-[var(--text)]">
                                    {{ $req->path }}
                                </td>
                                <td class="py-2.5 px-3">
                                    <span @class([
                                        'px-2 py-0.5 rounded text-[11px] font-bold font-mono',
                                        'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' => $req->status_code < 300,
                                        'bg-amber-500/10 text-amber-600 dark:text-amber-400' => $req->status_code >= 300 && $req->status_code < 400,
                                        'bg-rose-500/10 text-rose-600 dark:text-rose-400' => $req->status_code >= 400,
                                    ])>
                                        {{ $req->status_code }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-[var(--text-muted)]">
                                    {{ $req->token?->name ?? ($req->token?->prefix ? $req->token->prefix.'...' : 'Anonymous') }}
                                </td>
                                <td class="py-2.5 px-3 text-[var(--text-muted)]">
                                    {{ $req->duration_ms !== null ? "{$req->duration_ms}ms" : '&mdash;' }}
                                </td>
                                <td class="py-2.5 px-3 text-right text-[var(--text-muted)]">
                                    {{ $req->created_at->diffForHumans() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>

