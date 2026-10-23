<div class="space-y-6">
    <x-layout.page-header
        title="SSL / TLS Certificates: {{ $domain->domain }}"
        subtitle="Manage automated Let's Encrypt certificates, custom PEM certificates, HTTP-01 challenges, and modern TLS security."
    >
        <x-slot:actions>
            <x-ui.button variant="outline" onclick="window.location='{{ route('domains.index') }}'">
                &larr; Back to Domains
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    @if (session()->has('status'))
        <x-ui.chip variant="success" size="lg" class="w-full justify-start py-2.5 px-4 rounded-2xl">
            {{ session('status') }}
        </x-ui.chip>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Status & Toggles Column -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Active Certificate Status Card -->
            <x-ui.card title="Current SSL Status">
                @if ($certificate && $certificate->status->value === 'active')
                    <div class="space-y-3.5 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-medium text-[var(--text-muted)]">Status</span>
                            <x-ui.chip variant="success" size="sm" dot>
                                {{ $certificate->status->label() }}
                            </x-ui.chip>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="font-medium text-[var(--text-muted)]">Issuer</span>
                            <span class="font-mono text-[var(--text)] truncate max-w-[180px]">{{ $certificate->issuer }}</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="font-medium text-[var(--text-muted)]">Type</span>
                            <span class="text-[var(--text)]">{{ $certificate->type->label() }}</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="font-medium text-[var(--text-muted)]">Expires On</span>
                            <span class="font-mono text-[var(--text)]">{{ $certificate->valid_to?->toDateString() }}</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="font-medium text-[var(--text-muted)]">Days Remaining</span>
                            <span class="font-bold {{ ($certificate->daysRemaining() ?? 0) <= 30 ? 'text-amber-500' : 'text-emerald-500' }}">
                                {{ $certificate->daysRemaining() }} days
                            </span>
                        </div>
                    </div>
                @elseif ($certificate && $certificate->status->value === 'pending')
                    <div class="rounded-[20px] bg-amber-50 dark:bg-amber-500/10 border border-amber-300 dark:border-amber-500/20 p-4 text-xs text-amber-800 dark:text-amber-200 flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>Certificate issuance or installation is currently in progress...</span>
                    </div>
                @elseif ($certificate && $certificate->status->value === 'failed')
                    <div class="rounded-[20px] bg-rose-50 dark:bg-rose-500/10 border border-rose-300 dark:border-rose-500/20 p-4 text-xs text-rose-800 dark:text-rose-200">
                        <p class="font-bold mb-1">Issuance Failed</p>
                        <p class="font-mono text-[11px] text-rose-600 dark:text-rose-400 break-words">{{ $certificate->last_error }}</p>
                    </div>
                @else
                    <div class="text-center py-6 text-xs">
                        <div class="w-10 h-10 rounded-full bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text-muted)] flex items-center justify-center mx-auto mb-2">
                            &times;
                        </div>
                        <p class="font-bold text-[var(--text)] mb-1">No Active SSL Certificate</p>
                        <p class="text-[var(--text-muted)] text-[11px]">This domain is currently serving unencrypted traffic over plain HTTP (Port 80).</p>
                    </div>
                @endif
            </x-ui.card>

            <!-- Security Toggles -->
            <x-ui.card title="Security Options">
                <form wire:submit.prevent="saveToggles" class="space-y-4">
                    <!-- Force HTTPS -->
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input id="force_https" type="checkbox" wire:model="force_https"
                                class="w-4 h-4 rounded border-[var(--border)] text-blue-600 focus:ring-blue-500">
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="force_https" class="font-semibold text-xs text-[var(--text)]">Force HTTPS</label>
                            <p class="text-[var(--text-muted)] text-[11px]">Redirect all plain HTTP traffic to HTTPS (301).</p>
                        </div>
                    </div>

                    <!-- HSTS -->
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input id="hsts_enabled" type="checkbox" wire:model="hsts_enabled"
                                class="w-4 h-4 rounded border-[var(--border)] text-blue-600 focus:ring-blue-500">
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="hsts_enabled" class="font-semibold text-xs text-[var(--text)]">Enable HSTS</label>
                            <p class="text-[var(--text-muted)] text-[11px]">Strict-Transport-Security header (max-age=31536000).</p>
                        </div>
                    </div>

                    <!-- Auto Renew -->
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input id="auto_renew" type="checkbox" wire:model="auto_renew"
                                class="w-4 h-4 rounded border-[var(--border)] text-blue-600 focus:ring-blue-500">
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="auto_renew" class="font-semibold text-xs text-[var(--text)]">Auto-Renew Certificate</label>
                            <p class="text-[var(--text-muted)] text-[11px]">Automatically renew Let's Encrypt 30 days before expiration.</p>
                        </div>
                    </div>

                    <div class="pt-2">
                        <x-ui.button type="submit" variant="primary" size="sm" class="w-full" loading="saveToggles">
                            Save Security Settings
                        </x-ui.button>
                    </div>
                </form>

                @if ($certificate && $certificate->status->value === 'active')
                    <div class="mt-4 pt-4 border-t border-[var(--border)] text-center">
                        <x-ui.button variant="danger" size="xs" wire:click="revokeCertificate" wire:confirm="Are you sure you want to revoke this SSL certificate? Traffic will revert to plain HTTP.">
                            Revoke Certificate
                        </x-ui.button>
                    </div>
                @endif
            </x-ui.card>
        </div>

        <!-- Issue & Install Options Column -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Let's Encrypt Section -->
            <x-ui.card title="Automated Let's Encrypt Certificate" subtitle="Free, automated, and recognized by all modern web browsers.">
                <x-slot:headerActions>
                    <x-ui.chip variant="success" size="sm">HTTP-01 Challenge</x-ui.chip>
                </x-slot:headerActions>

                <div class="bg-[var(--bg-shell)] border border-[var(--border)] rounded-[20px] p-4 mb-4 text-xs text-[var(--text-muted)] space-y-1">
                    <p class="font-semibold text-[var(--text)]">Prerequisites for Let's Encrypt verification:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        <li>Public DNS A record for <strong>{{ $domain->domain }}</strong> must resolve to this server.</li>
                        <li>Port 80 must be accessible worldwide for the ACME challenge token.</li>
                    </ul>
                </div>

                <form wire:submit.prevent="requestLetsEncrypt" class="space-y-4">
                    <x-ui.input
                        name="email"
                        type="email"
                        label="Notification Email"
                        wire:model="email"
                        placeholder="admin@example.com"
                        hint="Used by Let's Encrypt to send critical expiration or renewal alerts."
                        required
                    />
                    @error('email') <span class="text-xs text-rose-500 mt-1 block ml-2">{{ $message }}</span> @enderror

                    <div class="flex justify-end pt-2">
                        <x-ui.button type="submit" variant="primary" loading="requestLetsEncrypt">
                            Request Let's Encrypt Certificate
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <!-- Custom SSL Certificate Section -->
            <x-ui.card title="Install Custom SSL Certificate" subtitle="Paste your externally purchased commercial certificate (e.g. Comodo, DigiCert, Sectigo, or Wildcard).">
                <form wire:submit.prevent="installCustomCertificate" class="space-y-4">
                    <div>
                        <x-ui.textarea
                            name="customCert"
                            label="Certificate (CRT / PEM)"
                            wire:model="customCert"
                            rows="4"
                            monospace
                            placeholder="-----BEGIN CERTIFICATE----- ... -----END CERTIFICATE-----"
                            required
                        />
                        @error('customCert') <span class="text-xs text-rose-500 mt-1 block ml-2">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.textarea
                            name="customKey"
                            label="Private Key (KEY / PEM)"
                            wire:model="customKey"
                            rows="4"
                            monospace
                            hint="Private keys are written with strict 0600 file permissions and never exposed via API or logs."
                            placeholder="-----BEGIN RSA PRIVATE KEY----- ... -----END RSA PRIVATE KEY-----"
                            required
                        />
                        @error('customKey') <span class="text-xs text-rose-500 mt-1 block ml-2">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.textarea
                            name="customChain"
                            label="Intermediate CA / Chain (Optional)"
                            wire:model="customChain"
                            rows="3"
                            monospace
                            placeholder="-----BEGIN CERTIFICATE----- ... -----END CERTIFICATE-----"
                        />
                        @error('customChain') <span class="text-xs text-rose-500 mt-1 block ml-2">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex justify-end pt-2">
                        <x-ui.button type="submit" variant="primary" loading="installCustomCertificate">
                            Install Custom Certificate
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>
    </div>
</div>
