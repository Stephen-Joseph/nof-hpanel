<div>
    <x-layout.page-header 
        title="DNS Verification & SSL: {{ $domain->domain }}" 
        subtitle="Connect your domain to the edge network using CNAME and TXT verification, then provision an automated SSL certificate."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('domains.index') }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Domains
                </x-ui.button>
                <x-ui.button href="{{ route('domains.ssl', $domain) }}" variant="outline" size="sm">
                    SSL Settings
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Session Feedback Alerts -->
    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="mb-6 rounded-[20px] bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-500 dark:text-rose-400">
            {{ session('error') }}
        </div>
    @endif

    <!-- Status Overview Bar -->
    <x-ui.card class="mb-6 p-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-xl bg-shell text-primary-500 border border-border">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-base font-semibold text-main">{{ $domain->domain }}</span>
                        @if ($domain->is_apex)
                            <x-ui.chip variant="primary" size="xs">Apex Domain</x-ui.chip>
                        @else
                            <x-ui.chip variant="neutral" size="xs">Subdomain</x-ui.chip>
                        @endif
                    </div>
                    <p class="text-xs text-muted mt-0.5">
                        @if ($domain->dns_last_checked_at)
                            Last checked: {{ $domain->dns_last_checked_at->diffForHumans() }}
                        @else
                            Not checked yet
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($domain->verification_status === 'verified')
                    <x-ui.chip variant="success" size="sm" dot>DNS Verified</x-ui.chip>
                @elseif ($domain->verification_status === 'failed')
                    <x-ui.chip variant="danger" size="sm" dot>DNS Failed</x-ui.chip>
                @else
                    <x-ui.chip variant="warning" size="sm" dot>DNS Pending</x-ui.chip>
                @endif

                @if ($domain->ssl_status === 'active')
                    <x-ui.chip variant="success" size="sm">SSL Active</x-ui.chip>
                @elseif ($domain->ssl_status === 'pending')
                    <x-ui.chip variant="warning" size="sm">SSL Issuing</x-ui.chip>
                @elseif ($domain->ssl_status === 'failed')
                    <x-ui.chip variant="danger" size="sm">SSL Failed</x-ui.chip>
                @else
                    <x-ui.chip variant="neutral" size="sm">No SSL</x-ui.chip>
                @endif
            </div>
        </div>

        @if ($domain->dns_last_error)
            <div class="mt-4 p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-700 dark:text-amber-300">
                <span class="font-semibold">Notice:</span> {{ $domain->dns_last_error }} DNS changes can take up to 24 hours to propagate globally.
            </div>
        @endif
    </x-ui.card>

    <!-- 4-Step Verification & Setup Workflow -->
    <div class="space-y-4">
        <!-- Step 1: Routing Record -->
        <x-ui.card class="p-6">
            <div class="flex items-start gap-4">
                <div class="w-8 h-8 rounded-full bg-primary-500/10 text-primary-600 dark:text-primary-400 font-bold flex items-center justify-center shrink-0 text-sm">
                    1
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-semibold text-main">Required DNS Records: Routing</h3>

                    @if ($domain->is_apex)
                        <div class="mt-2 text-xs text-muted leading-relaxed">
                            <p class="font-medium text-main">Your domain is a root/apex domain ({{ $domain->domain }}).</p>
                            <p class="mt-1">Standard DNS rules (RFC 1034/1912) prohibit CNAME records at the apex zone.</p>
                            <div class="mt-3 p-3 bg-shell rounded-xl border border-border text-xs text-main">
                                <span class="font-semibold text-primary-500">Recommended:</span>
                                Use an <strong>ALIAS</strong>, <strong>ANAME</strong>, or <strong>CNAME Flattening</strong> record pointing directly to your edge host:
                                <div class="mt-2 flex items-center gap-2" x-data="{ copied: false }">
                                    <code class="px-2.5 py-1 rounded-lg bg-card border border-border font-mono text-xs text-main font-semibold select-all">{{ $edgeHost }}</code>
                                    <button type="button" x-on:click="navigator.clipboard.writeText('{{ $edgeHost }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                        class="px-2.5 py-1 text-xs rounded-lg border border-border bg-card hover:bg-shell text-muted hover:text-main transition cursor-pointer">
                                        <span x-show="!copied">Copy</span>
                                        <span x-show="copied" class="text-emerald-500 font-semibold">Copied!</span>
                                    </button>
                                </div>
                                <p class="mt-2 text-[11px] text-muted">Alternatively, create A/AAAA records that match the resolved IP addresses of <code class="font-mono">{{ $edgeHost }}</code>.</p>
                            </div>
                            <div class="mt-3">
                                <span class="font-semibold">For WWW subdomain:</span> Add a standard CNAME record for <code class="font-mono">www</code> pointing to <code class="font-mono">{{ $edgeHost }}</code>.
                            </div>
                        </div>
                    @else
                        <p class="mt-1 text-xs text-muted">Create a CNAME record at your DNS provider directing traffic to our edge hostname:</p>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="border-b border-border text-muted font-medium bg-shell/50">
                                        <th class="py-2 px-3">Type</th>
                                        <th class="py-2 px-3">Host / Name</th>
                                        <th class="py-2 px-3">Points To (Target)</th>
                                        <th class="py-2 px-3 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="text-main" x-data="{ copied: false }">
                                        <td class="py-2.5 px-3 font-mono font-bold text-primary-500">CNAME</td>
                                        <td class="py-2.5 px-3 font-mono">{{ $domain->domain }}</td>
                                        <td class="py-2.5 px-3 font-mono font-semibold">{{ $edgeHost }}</td>
                                        <td class="py-2.5 px-3 text-right">
                                            <button type="button" x-on:click="navigator.clipboard.writeText('{{ $edgeHost }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                class="px-2.5 py-1 text-xs rounded-lg border border-border bg-card hover:bg-shell text-muted hover:text-main transition cursor-pointer">
                                                <span x-show="!copied">Copy Value</span>
                                                <span x-show="copied" class="text-emerald-500 font-semibold">Copied!</span>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </x-ui.card>

        <!-- Step 2: Ownership Verification TXT Record -->
        <x-ui.card class="p-6">
            <div class="flex items-start gap-4">
                <div class="w-8 h-8 rounded-full bg-primary-500/10 text-primary-600 dark:text-primary-400 font-bold flex items-center justify-center shrink-0 text-sm">
                    2
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-semibold text-main">Add Ownership Verification TXT Record</h3>
                    <p class="mt-1 text-xs text-muted">Add this TXT record at your domain's DNS zone to verify administrative control:</p>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-border text-muted font-medium bg-shell/50">
                                    <th class="py-2 px-3">Type</th>
                                    <th class="py-2 px-3">Host / Name</th>
                                    <th class="py-2 px-3">TXT Value</th>
                                    <th class="py-2 px-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="text-main" x-data="{ copiedHost: false, copiedValue: false }">
                                    <td class="py-2.5 px-3 font-mono font-bold text-primary-500">TXT</td>
                                    <td class="py-2.5 px-3 font-mono font-semibold">
                                        {{ $txtHost }}
                                    </td>
                                    <td class="py-2.5 px-3 font-mono text-muted truncate max-w-md select-all">
                                        {{ $txtValue }}
                                    </td>
                                    <td class="py-2.5 px-3 text-right space-x-1 whitespace-nowrap">
                                        <button type="button" x-on:click="navigator.clipboard.writeText('{{ $txtHost }}'); copiedHost = true; setTimeout(() => copiedHost = false, 2000)"
                                            class="px-2 py-1 text-xs rounded-lg border border-border bg-card hover:bg-shell text-muted hover:text-main transition cursor-pointer">
                                            <span x-show="!copiedHost">Copy Host</span>
                                            <span x-show="copiedHost" class="text-emerald-500 font-semibold">Copied!</span>
                                        </button>
                                        <button type="button" x-on:click="navigator.clipboard.writeText('{{ $txtValue }}'); copiedValue = true; setTimeout(() => copiedValue = false, 2000)"
                                            class="px-2 py-1 text-xs rounded-lg border border-border bg-card hover:bg-shell text-muted hover:text-main transition cursor-pointer">
                                            <span x-show="!copiedValue">Copy Value</span>
                                            <span x-show="copiedValue" class="text-emerald-500 font-semibold">Copied!</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <!-- Step 3: Verify DNS Now -->
        <x-ui.card class="p-6">
            <div class="flex items-start gap-4">
                <div class="w-8 h-8 rounded-full {{ $domain->verification_status === 'verified' ? 'bg-emerald-500/10 text-emerald-500' : 'bg-primary-500/10 text-primary-600 dark:text-primary-400' }} font-bold flex items-center justify-center shrink-0 text-sm">
                    @if ($domain->verification_status === 'verified')
                        &check;
                    @else
                        3
                    @endif
                </div>
                <div class="flex-1">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-main">Verify Live DNS Resolution</h3>
                            <p class="text-xs text-muted mt-0.5">We will query public DNS to verify both ownership (TXT) and routing (CNAME or A/AAAA).</p>
                        </div>
                        <x-ui.button wire:click="verifyDns" wire:loading.attr="disabled" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="verifyDns">Verify Now</span>
                            <span wire:loading wire:target="verifyDns" class="flex items-center gap-1.5">
                                <svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                Verifying...
                            </span>
                        </x-ui.button>
                    </div>

                    @if ($checkMessage)
                        <div class="mt-3 p-3 rounded-xl {{ $checkSuccess ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/10 border border-rose-500/20 text-rose-500 dark:text-rose-400' }} text-xs">
                            {{ $checkMessage }}
                        </div>
                    @endif
                </div>
            </div>
        </x-ui.card>

        <!-- Step 4: Enable SSL -->
        <x-ui.card class="p-6">
            <div class="flex items-start gap-4">
                <div class="w-8 h-8 rounded-full {{ $domain->ssl_status === 'active' ? 'bg-emerald-500/10 text-emerald-500' : ($domain->verification_status === 'verified' ? 'bg-primary-500/10 text-primary-600 dark:text-primary-400' : 'bg-shell text-muted') }} font-bold flex items-center justify-center shrink-0 text-sm">
                    @if ($domain->ssl_status === 'active')
                        &check;
                    @else
                        4
                    @endif
                </div>
                <div class="flex-1">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-main">Enable Free Automated SSL (Let's Encrypt)</h3>
                            <p class="text-xs text-muted mt-0.5">
                                @if ($domain->ssl_status === 'active')
                                    Certificate active and auto-renewing.
                                    @if ($domain->ssl_expires_at)
                                        Expires {{ $domain->ssl_expires_at->diffForHumans() }} ({{ $domain->ssl_expires_at->toDateString() }}).
                                    @endif
                                @elseif ($domain->ssl_status === 'pending')
                                    Certificate issuance request is currently processing in the background...
                                @elseif ($domain->ssl_status === 'failed')
                                    Issuance failed. Please check DNS propagation and retry.
                                @else
                                    Once verified, request a Let's Encrypt HTTP-01 certificate through our secure server agent.
                                @endif
                            </p>
                        </div>

                        <div>
                            @if ($domain->verification_status === 'verified')
                                <x-ui.button wire:click="enableSsl" wire:loading.attr="disabled" variant="{{ $domain->ssl_status === 'active' ? 'outline' : 'primary' }}" size="sm">
                                    <span wire:loading.remove wire:target="enableSsl">
                                        {{ $domain->ssl_status === 'active' ? 'Renew / Re-issue SSL' : 'Enable SSL' }}
                                    </span>
                                    <span wire:loading wire:target="enableSsl" class="flex items-center gap-1.5">
                                        <svg class="animate-spin h-3.5 w-3.5 text-current" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                        </svg>
                                        Requesting SSL...
                                    </span>
                                </x-ui.button>
                            @else
                                <x-ui.button disabled variant="outline" size="sm" class="opacity-50 cursor-not-allowed">
                                    Enable SSL
                                </x-ui.button>
                            @endif
                        </div>
                    </div>

                    @if ($domain->verification_status !== 'verified')
                        <p class="text-xs text-muted mt-2">
                            * Enabled only when domain DNS verification is complete (Step 3).
                        </p>
                    @endif

                    @if ($domain->ssl_last_error)
                        <div class="mt-3 p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-xs text-rose-500 dark:text-rose-400">
                            <span class="font-semibold">SSL Error:</span> {{ $domain->ssl_last_error }}
                        </div>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
