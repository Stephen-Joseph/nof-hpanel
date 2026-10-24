<div class="space-y-6">
    <x-layout.page-header 
        title="Webhooks" 
        subtitle="Subscribe to real-time asynchronous HTTP events with HMAC-SHA256 signature verification."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('api.integration.overview') }}" variant="outline" size="sm">
                &larr; Overview
            </x-ui.button>
            <x-ui.button href="{{ route('api.integration.docs') }}" variant="outline" size="sm">
                API Docs
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    @if (session('status'))
        <div class="p-4 rounded-xl border border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-sm flex items-center justify-between">
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <!-- Add Endpoint Form -->
    <x-ui.card class="space-y-4">
        <h3 class="text-base font-semibold text-[var(--text)] border-b border-[var(--border)] pb-3">Register Webhook Endpoint</h3>

        <form wire:submit="createEndpoint" novalidate class="space-y-4">
            <div>
                <label class="block text-xs font-medium text-[var(--text-muted)] mb-1.5">Destination URL (Must be HTTPS)</label>
                <x-ui.input 
                    type="url" 
                    wire:model="url" 
                    placeholder="https://billing.example.com/api/hpanel-webhook" 
                    required 
                    class="font-mono"
                />
                <p class="text-[11px] text-[var(--text-muted)] mt-1.5">Your endpoint will receive POST payloads with signature in <code class="font-mono text-[var(--text)]">X-HPanel-Signature</code>.</p>
                @error('url') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-[var(--text-muted)] mb-2">Subscribed Events</label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
                    @foreach ($availableEvents as $eventKey => $eventDesc)
                        <label class="flex items-start gap-2.5 p-2.5 rounded-xl border border-[var(--border)] hover:bg-[var(--bg-shell)] cursor-pointer transition-colors">
                            <input 
                                type="checkbox" 
                                wire:model="events" 
                                value="{{ $eventKey }}"
                                class="mt-0.5 rounded border-[var(--border)] text-blue-600 focus:ring-blue-500"
                            />
                            <div class="text-xs">
                                <span class="font-mono font-bold text-[var(--text)]">{{ $eventKey }}</span>
                                <p class="text-[var(--text-muted)] text-[11px] mt-0.5">{{ $eventDesc }}</p>
                            </div>
                        </label>
                    @endforeach
                </div>
                @error('events') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end pt-2 border-t border-[var(--border)]">
                <x-ui.button type="submit" variant="primary" size="sm">
                    Register Endpoint
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <!-- Registered Endpoints -->
    <x-ui.card class="space-y-4">
        <h3 class="text-base font-semibold text-[var(--text)] border-b border-[var(--border)] pb-3">Configured Endpoints</h3>

        @if ($endpoints->isEmpty())
            <x-ui.empty-state
                title="No webhooks registered"
                description="Register a webhook URL above to start receiving asynchronous notifications."
            />
        @else
            <div class="divide-y divide-[var(--border)]">
                @foreach ($endpoints as $ep)
                    <div class="py-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="space-y-1.5 max-w-xl">
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-bold text-xs text-[var(--text)] break-all">{{ $ep->url }}</span>
                                <x-ui.chip :variant="$ep->is_active ? 'success' : 'neutral'">
                                    {{ $ep->is_active ? 'Active' : 'Disabled' }}
                                </x-ui.chip>
                            </div>

                            <div class="flex flex-wrap gap-1">
                                @foreach ($ep->events as $ev)
                                    <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text-muted)]">
                                        {{ $ev }}
                                    </span>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <x-ui.button 
                                type="button" 
                                variant="outline" 
                                size="xs"
                                wire:click="sendTestEvent({{ $ep->id }})"
                                title="Send test ping event"
                            >
                                Send test event
                            </x-ui.button>

                            <x-ui.button 
                                type="button" 
                                variant="outline" 
                                size="xs"
                                wire:click="regenerateSecret({{ $ep->id }})"
                                wire:confirm="Regenerate signing secret for this endpoint? Existing integrations must be updated with the new secret."
                                title="Regenerate Secret"
                            >
                                Regenerate Secret
                            </x-ui.button>

                            <x-ui.button 
                                type="button" 
                                variant="ghost" 
                                size="xs"
                                wire:click="toggleActive({{ $ep->id }})"
                            >
                                {{ $ep->is_active ? 'Disable' : 'Enable' }}
                            </x-ui.button>

                            <x-ui.button 
                                type="button" 
                                variant="ghost" 
                                size="xs"
                                class="text-rose-500 hover:text-rose-600 hover:bg-rose-500/10"
                                wire:click="deleteEndpoint({{ $ep->id }})"
                                wire:confirm="Delete this webhook endpoint?"
                            >
                                Delete
                            </x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    <!-- Deliveries Table with Retry Action -->
    <x-ui.card class="space-y-4">
        <div class="border-b border-[var(--border)] pb-3">
            <h3 class="text-base font-semibold text-[var(--text)]">Delivery Telemetry</h3>
            <p class="text-xs text-[var(--text-muted)]">History of dispatched HTTP webhook deliveries, status responses, and errors.</p>
        </div>

        @if ($deliveries->isEmpty())
            <x-ui.empty-state
                title="No delivery logs"
                description="Delivery records will appear here as system events fire."
            />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="border-b border-[var(--border)] bg-[var(--bg-shell)] text-[var(--text-muted)] uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="py-2.5 px-3">Delivery ID</th>
                            <th class="py-2.5 px-3">Event</th>
                            <th class="py-2.5 px-3">Status</th>
                            <th class="py-2.5 px-3">Attempts</th>
                            <th class="py-2.5 px-3">Result / Error</th>
                            <th class="py-2.5 px-3">Timestamp</th>
                            <th class="py-2.5 px-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)] text-[var(--text)]">
                        @foreach ($deliveries as $del)
                            @php
                                $isSuccess = $del->status_code >= 200 && $del->status_code < 300;
                            @endphp
                            <tr class="hover:bg-[var(--bg-shell)] transition-colors">
                                <td class="py-2.5 px-3 font-semibold text-[var(--text-muted)]">
                                    #{{ $del->id }}
                                </td>
                                <td class="py-2.5 px-3 font-semibold text-[var(--text)]">
                                    {{ $del->event }}
                                </td>
                                <td class="py-2.5 px-3">
                                    @if ($del->status_code)
                                        <span @class([
                                            'px-2 py-0.5 rounded text-[11px] font-bold font-mono',
                                            'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' => $isSuccess,
                                            'bg-rose-500/10 text-rose-600 dark:text-rose-400' => ! $isSuccess,
                                        ])>
                                            HTTP {{ $del->status_code }}
                                        </span>
                                    @else
                                        <x-ui.chip variant="warning">Pending</x-ui.chip>
                                    @endif
                                </td>
                                <td class="py-2.5 px-3 text-[var(--text-muted)]">
                                    {{ $del->attempts }}
                                </td>
                                <td class="py-2.5 px-3 text-xs max-w-xs truncate text-[var(--text-muted)]" title="{{ $del->error ?? $del->response_body }}">
                                    {{ $del->error ?? ($isSuccess ? 'Delivered' : $del->response_body) }}
                                </td>
                                <td class="py-2.5 px-3 text-[var(--text-muted)]">
                                    {{ $del->created_at->diffForHumans() }}
                                </td>
                                <td class="py-2.5 px-3 text-right">
                                    <x-ui.button 
                                        type="button" 
                                        variant="outline" 
                                        size="xs"
                                        wire:click="retryDelivery({{ $del->id }})"
                                        title="Retry dispatch"
                                    >
                                        Retry
                                    </x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pt-3 border-t border-[var(--border)]">
                {{ $deliveries->links() }}
            </div>
        @endif
    </x-ui.card>

    <!-- Modal: Display Webhook Secret -->
    <x-ui.modal name="secret-modal" max-width="md">
        <div class="p-6 space-y-4" x-data="{ copied: false }">
            <div class="flex items-center gap-3 text-emerald-500">
                <div class="w-10 h-10 rounded-full bg-emerald-500/10 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-[var(--text)]">Webhook Signing Secret</h3>
                    <p class="text-xs text-[var(--text-muted)] truncate max-w-xs">{{ $newSecretUrl }}</p>
                </div>
            </div>

            <div class="p-3 rounded-xl bg-blue-500/10 border border-blue-500/20 text-blue-700 dark:text-blue-400 text-xs">
                Use this secret key to verify HMAC-SHA256 signatures in the <code>X-HPanel-Signature</code> header.
            </div>

            <div class="p-3 rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] font-mono text-xs text-[var(--text)] break-all select-all">
                {{ $newSecret }}
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-[var(--border)]">
                <x-ui.button 
                    type="button" 
                    variant="outline" 
                    size="sm"
                    @click="navigator.clipboard.writeText('{{ $newSecret }}'); copied = true; setTimeout(() => copied = false, 2000)"
                >
                    <svg x-show="!copied" class="w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    <svg x-show="copied" x-cloak class="w-3.5 h-3.5 mr-1.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <span x-text="copied ? 'Copied!' : 'Copy Secret'"></span>
                </x-ui.button>

                <x-ui.button type="button" variant="primary" size="sm" x-on:click="$dispatch('close-modal', 'secret-modal')">
                    Done
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>

