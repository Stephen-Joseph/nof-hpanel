<div class="max-w-3xl mx-auto space-y-6">
    <x-layout.page-header 
        title="Create API Token" 
        subtitle="Generate a new API credential with restricted scopes and IP allow-listing."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('api.integration.tokens') }}" variant="outline" size="sm">
                &larr; Back to Tokens
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <form wire:submit="createToken" novalidate class="space-y-6">
        <!-- Token Name & Expiry -->
        <x-ui.card class="space-y-4">
            <h3 class="text-base font-semibold text-[var(--text)] border-b border-[var(--border)] pb-3">Identity &amp; Lifecycle</h3>

            <div>
                <label class="block text-xs font-medium text-[var(--text-muted)] mb-1.5">Token Name / Description</label>
                <x-ui.input 
                    type="text" 
                    wire:model="name" 
                    placeholder="e.g. WHMCS Provisioning Module or CI/CD Deployment"
                    required
                />
                <p class="text-[11px] text-[var(--text-muted)] mt-1.5">A human-readable label to identify who or what uses this token.</p>
                @error('name') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-[var(--text-muted)] mb-1.5">Expiration Period</label>
                <x-ui.select wire:model="expiry" required>
                    <option value="365">1 Year (365 days) &mdash; Recommended</option>
                    <option value="90">90 Days</option>
                    <option value="30">30 Days</option>
                    <option value="never">Never Expires (Permanent)</option>
                </x-ui.select>
                <p class="text-[11px] text-[var(--text-muted)] mt-1.5">Tokens automatically become invalid when their expiration date passes.</p>
                @error('expiry') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>
        </x-ui.card>

        <!-- Scopes -->
        <x-ui.card class="space-y-4">
            <div class="flex items-center justify-between border-b border-[var(--border)] pb-3">
                <div>
                    <h3 class="text-base font-semibold text-[var(--text)]">Permissions &amp; Scopes</h3>
                    <p class="text-xs text-[var(--text-muted)]">Select least-privilege scopes required for your integration.</p>
                </div>
                <div class="flex items-center gap-2">
                    <x-ui.button 
                        type="button" 
                        variant="ghost" 
                        size="xs" 
                        @click="@this.set('scopes', @js(array_keys($availableScopes)))"
                    >
                        Select All
                    </x-ui.button>
                    <x-ui.button 
                        type="button" 
                        variant="ghost" 
                        size="xs" 
                        @click="@this.set('scopes', [])"
                    >
                        Deselect All
                    </x-ui.button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach ($availableScopes as $scopeKey => $scopeDesc)
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-[var(--border)] hover:bg-[var(--bg-shell)] cursor-pointer transition-colors">
                        <input 
                            type="checkbox" 
                            wire:model="scopes" 
                            value="{{ $scopeKey }}"
                            class="mt-1 rounded border-[var(--border)] text-blue-600 focus:ring-blue-500"
                        />
                        <div class="text-xs">
                            <span class="font-mono font-bold text-[var(--text)] block">{{ $scopeKey }}</span>
                            <span class="text-[var(--text-muted)] text-[11px] block mt-0.5">{{ $scopeDesc }}</span>
                        </div>
                    </label>
                @endforeach
            </div>
            @error('scopes') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
        </x-ui.card>

        <!-- IP Allowlist -->
        <x-ui.card class="space-y-4">
            <h3 class="text-base font-semibold text-[var(--text)] border-b border-[var(--border)] pb-3">IP Address Allow-list (Optional)</h3>

            <div>
                <label class="block text-xs font-medium text-[var(--text-muted)] mb-1.5">Allowed IPs or CIDRs (one per line)</label>
                <textarea 
                    wire:model="allowedIps" 
                    rows="4" 
                    placeholder="203.0.113.15&#10;198.51.100.0/24"
                    class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] p-3 font-mono text-xs text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-blue-500"
                ></textarea>
                <p class="text-[11px] text-[var(--text-muted)] mt-1.5">If specified, incoming API requests will strictly be restricted to these source addresses.</p>
                @error('allowedIps') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror
            </div>
        </x-ui.card>

        <!-- Submit & Cancel -->
        <div class="flex items-center justify-end gap-3">
            <x-ui.button href="{{ route('api.integration.tokens') }}" variant="ghost" size="sm">
                Cancel
            </x-ui.button>
            <x-ui.button type="submit" variant="primary" size="sm">
                Generate Token
            </x-ui.button>
        </div>
    </form>

    <!-- Modal: Display Generated Plain Token -->
    <x-ui.modal name="token-created-modal" max-width="lg">
        <div class="p-6 space-y-4" x-data="{ copied: false }">
            <div class="flex items-center gap-3 text-emerald-500">
                <div class="w-10 h-10 rounded-full bg-emerald-500/10 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-[var(--text)]">API Token Created</h3>
                    <p class="text-xs text-[var(--text-muted)]">Copy your secret key now. It will never be shown again.</p>
                </div>
            </div>

            <div class="p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-400 text-xs leading-relaxed">
                <strong>Important:</strong> Store this token securely (e.g. in your billing module or environment variables). We only store a one-way SHA-256 hash in our database.
            </div>

            <div class="relative">
                <div class="p-3.5 rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] font-mono text-xs text-[var(--text)] break-all select-all">
                    {{ $generatedPlainTextToken }}
                </div>
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-[var(--border)]">
                <x-ui.button 
                    type="button" 
                    variant="outline" 
                    size="sm"
                    @click="navigator.clipboard.writeText('{{ $generatedPlainTextToken }}'); copied = true; setTimeout(() => copied = false, 2000)"
                >
                    <svg x-show="!copied" class="w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    <svg x-show="copied" x-cloak class="w-3.5 h-3.5 mr-1.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <span x-text="copied ? 'Copied to Clipboard!' : 'Copy Token'"></span>
                </x-ui.button>

                <x-ui.button href="{{ route('api.integration.tokens') }}" variant="primary" size="sm">
                    Go to Tokens
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>

