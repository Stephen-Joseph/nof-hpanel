<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('domains.index') }}" class="text-xs text-indigo-400 hover:text-indigo-300 transition">&larr; Back to Domains</a>
            </div>
            <h2 class="text-2xl font-bold text-white tracking-tight">SSL / TLS Certificates: {{ $domain->domain }}</h2>
            <p class="text-sm text-slate-400 mt-1">Manage automated Let's Encrypt certificates, custom PEM certificates, HTTP-01 challenges, and modern TLS security.</p>
        </div>
    </div>

    @if (session()->has('status'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Status & Toggles Column -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Active Certificate Status Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <h3 class="text-base font-semibold text-white mb-4">Current SSL Status</h3>

                @if ($certificate && $certificate->status->value === 'active')
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-slate-400">Status</span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $certificate->status->badgeClasses() }}">
                                &check; {{ $certificate->status->label() }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-slate-400">Issuer</span>
                            <span class="text-xs font-mono text-white truncate max-w-[180px]">{{ $certificate->issuer }}</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-slate-400">Type</span>
                            <span class="text-xs text-slate-300">{{ $certificate->type->label() }}</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-slate-400">Expires On</span>
                            <span class="text-xs font-mono text-slate-300">{{ $certificate->valid_to?->toDateString() }}</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-slate-400">Days Remaining</span>
                            <span class="text-xs font-semibold {{ ($certificate->daysRemaining() ?? 0) <= 30 ? 'text-amber-400' : 'text-emerald-400' }}">
                                {{ $certificate->daysRemaining() }} days
                            </span>
                        </div>
                    </div>
                @elseif ($certificate && $certificate->status->value === 'pending')
                    <div class="rounded-lg bg-amber-500/10 border border-amber-500/20 p-4 text-xs text-amber-300 flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4 text-amber-400" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>Certificate issuance or installation is currently in progress...</span>
                    </div>
                @elseif ($certificate && $certificate->status->value === 'failed')
                    <div class="rounded-lg bg-rose-500/10 border border-rose-500/20 p-4 text-xs text-rose-300">
                        <p class="font-semibold mb-1">Issuance Failed</p>
                        <p class="font-mono text-[11px] text-rose-400 break-words">{{ $certificate->last_error }}</p>
                    </div>
                @else
                    <div class="text-center py-6 text-slate-400 text-xs">
                        <div class="w-10 h-10 rounded-full bg-slate-800 text-slate-400 flex items-center justify-center mx-auto mb-2">
                            &times;
                        </div>
                        <p class="font-medium text-white mb-1">No Active SSL Certificate</p>
                        <p class="text-slate-500 text-[11px]">This domain is currently serving unencrypted traffic over plain HTTP (Port 80).</p>
                    </div>
                @endif
            </div>

            <!-- Security Toggles -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <h3 class="text-base font-semibold text-white mb-4">Security Options</h3>

                <form wire:submit.prevent="saveToggles" class="space-y-4">
                    <!-- Force HTTPS -->
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input id="force_https" type="checkbox" wire:model="force_https"
                                class="w-4 h-4 rounded border-slate-700 bg-slate-800 text-indigo-600 focus:ring-indigo-500">
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="force_https" class="font-medium text-white">Force HTTPS</label>
                            <p class="text-slate-400 text-xs mt-0.5">Redirect all plain HTTP traffic to HTTPS (301).</p>
                        </div>
                    </div>

                    <!-- HSTS -->
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input id="hsts_enabled" type="checkbox" wire:model="hsts_enabled"
                                class="w-4 h-4 rounded border-slate-700 bg-slate-800 text-indigo-600 focus:ring-indigo-500">
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="hsts_enabled" class="font-medium text-white">Enable HSTS</label>
                            <p class="text-slate-400 text-xs mt-0.5">Strict-Transport-Security header (max-age=31536000).</p>
                        </div>
                    </div>

                    <!-- Auto Renew -->
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input id="auto_renew" type="checkbox" wire:model="auto_renew"
                                class="w-4 h-4 rounded border-slate-700 bg-slate-800 text-indigo-600 focus:ring-indigo-500">
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="auto_renew" class="font-medium text-white">Auto-Renew Certificate</label>
                            <p class="text-slate-400 text-xs mt-0.5">Automatically renew Let's Encrypt 30 days before expiration.</p>
                        </div>
                    </div>

                    <div class="pt-2">
                        <button type="submit"
                            class="w-full py-2 px-4 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm focus:outline-none transition">
                            Save Security Settings
                        </button>
                    </div>
                </form>

                @if ($certificate && $certificate->status->value === 'active')
                    <div class="mt-4 pt-4 border-t border-slate-800 text-center">
                        <button wire:click="revokeCertificate" wire:confirm="Are you sure you want to revoke this SSL certificate? Traffic will revert to plain HTTP."
                            class="text-xs text-rose-400 hover:text-rose-300 font-medium transition">
                            Revoke Certificate
                        </button>
                    </div>
                @endif
            </div>
        </div>

        <!-- Issue & Install Options Column -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Let's Encrypt Section -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-base font-semibold text-white">Automated Let's Encrypt Certificate</h3>
                        <p class="text-xs text-slate-400 mt-0.5">Free, automated, and recognized by all modern web browsers.</p>
                    </div>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                        HTTP-01 Challenge
                    </span>
                </div>

                <div class="bg-slate-800/40 border border-slate-800 rounded-lg p-4 mb-4 text-xs text-slate-400 space-y-1">
                    <p class="font-semibold text-slate-300">Prerequisites for Let's Encrypt verification:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        <li>Public DNS A record for <strong>{{ $domain->domain }}</strong> must resolve to this server.</li>
                        <li>Port 80 must be accessible worldwide for the ACME challenge token.</li>
                    </ul>
                </div>

                <form wire:submit.prevent="requestLetsEncrypt" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Notification Email</label>
                        <input type="email" wire:model="email" placeholder="admin@example.com"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                        @error('email') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                        <p class="text-[11px] text-slate-500 mt-1">Used by Let's Encrypt to send critical expiration or renewal alerts.</p>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 py-2 px-4 rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-xs font-semibold shadow-sm focus:outline-none transition">
                            <span wire:loading.remove wire:target="requestLetsEncrypt">Request Let's Encrypt Certificate</span>
                            <span wire:loading wire:target="requestLetsEncrypt" class="flex items-center gap-1.5">
                                <svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                Requesting...
                            </span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Custom SSL Certificate Section -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <h3 class="text-base font-semibold text-white mb-1">Install Custom SSL Certificate</h3>
                <p class="text-xs text-slate-400 mb-4">Paste your externally purchased commercial certificate (e.g. Comodo, DigiCert, Sectigo, or Wildcard).</p>

                <form wire:submit.prevent="installCustomCertificate" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Certificate (CRT / PEM)</label>
                        <textarea wire:model="customCert" rows="4" placeholder="-----BEGIN CERTIFICATE----- ... -----END CERTIFICATE-----"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white font-mono text-xs px-3 py-2 border focus:ring-1 focus:ring-indigo-500"></textarea>
                        @error('customCert') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Private Key (KEY / PEM)</label>
                        <textarea wire:model="customKey" rows="4" placeholder="-----BEGIN RSA PRIVATE KEY----- ... -----END RSA PRIVATE KEY-----"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white font-mono text-xs px-3 py-2 border focus:ring-1 focus:ring-indigo-500"></textarea>
                        @error('customKey') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                        <p class="text-[11px] text-slate-500 mt-1">Private keys are written with strict 0600 file permissions and never exposed via API or logs.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Intermediate CA / Chain (Optional)</label>
                        <textarea wire:model="customChain" rows="3" placeholder="-----BEGIN CERTIFICATE----- ... -----END CERTIFICATE-----"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white font-mono text-xs px-3 py-2 border focus:ring-1 focus:ring-indigo-500"></textarea>
                        @error('customChain') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit"
                            class="py-2 px-4 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm focus:outline-none transition">
                            Install Custom Certificate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

