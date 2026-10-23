<div>
    <x-layout.page-header 
        title="DNS Configuration & Verification: {{ $domain->domain }}" 
        subtitle="Review required DNS records for your domain and verify live public DNS propagation."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('domains.index') }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Domains
                </x-ui.button>
                <x-ui.button wire:click="checkDns" wire:loading.attr="disabled" variant="primary" size="sm">
                    <span wire:loading.remove wire:target="checkDns">Verify DNS Resolution</span>
                    <span wire:loading wire:target="checkDns" class="flex items-center gap-1.5">
                        <svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        Checking...
                    </span>
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Live Check Results -->
    @if ($hasChecked)
        <div class="mb-6">
            @if ($checkStatus === 'pointing')
                <div class="rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-emerald-500/20 text-emerald-500 flex items-center justify-center shrink-0 font-bold">
                        &check;
                    </div>
                    <div>
                        <h4 class="text-sm font-semibold text-emerald-600 dark:text-emerald-300">DNS Properly Configured</h4>
                        <p class="text-xs text-emerald-700/90 dark:text-emerald-400/90 mt-0.5">
                            <strong>{{ $domain->domain }}</strong> resolves to <strong>{{ $resolvedIp }}</strong>, matching your target server (<span class="font-mono">{{ $domain->account->server->name }}</span>). Web traffic and SSL issuance are ready.
                        </p>
                    </div>
                </div>
            @elseif ($checkStatus === 'mismatch')
                <div class="rounded-[20px] bg-amber-500/10 border border-amber-500/20 p-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-amber-500/20 text-amber-500 flex items-center justify-center shrink-0 font-bold">
                        !
                    </div>
                    <div>
                        <h4 class="text-sm font-semibold text-amber-600 dark:text-amber-300">DNS IP Mismatch</h4>
                        <p class="text-xs text-amber-700/90 dark:text-amber-400/90 mt-0.5">
                            <strong>{{ $domain->domain }}</strong> resolves to <strong>{{ $resolvedIp }}</strong>, but this server's IP is <strong>{{ $domain->account->server->private_ip }}</strong>.
                            If you recently updated DNS records, please allow up to 24-48 hours for global DNS cache propagation.
                        </p>
                    </div>
                </div>
            @else
                <div class="rounded-[20px] bg-rose-500/10 border border-rose-500/20 p-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-rose-500/20 text-rose-500 flex items-center justify-center shrink-0 font-bold">
                        &times;
                    </div>
                    <div>
                        <h4 class="text-sm font-semibold text-rose-600 dark:text-rose-300">No DNS Records Found</h4>
                        <p class="text-xs text-rose-700/90 dark:text-rose-400/90 mt-0.5">
                            Could not resolve any A records for <strong>{{ $domain->domain }}</strong>. Please check your domain registrar or authoritative DNS nameserver configuration.
                        </p>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <!-- Recommended DNS Records -->
    <x-ui.card class="mb-6 p-0 overflow-hidden">
        <div class="px-6 py-4 border-b border-border flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold text-main">Required DNS Records</h3>
                <p class="text-xs text-muted mt-0.5">Add these records at your DNS registrar or Cloudflare to direct visitors to this server.</p>
            </div>
            <span class="text-xs font-mono text-muted bg-shell px-2.5 py-1 rounded-full border border-border">Target Server: {{ $domain->account->server->name }} ({{ $domain->account->server->private_ip }})</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="py-3 px-6">Type</th>
                        <th class="py-3 px-6">Host / Name</th>
                        <th class="py-3 px-6">Points To / Value</th>
                        <th class="py-3 px-6">TTL</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-main">
                    @if ($domain->type->value === 'subdomain')
                        <tr class="hover:bg-shell/40 transition-colors">
                            <td class="px-6 py-3.5 font-mono text-xs text-primary-500 font-bold">A</td>
                            <td class="px-6 py-3.5 font-mono text-xs font-semibold">{{ explode('.', $domain->domain)[0] }}</td>
                            <td class="px-6 py-3.5 font-mono text-xs text-muted">{{ $domain->account->server->private_ip }}</td>
                            <td class="px-6 py-3.5 text-xs text-muted">Auto / 300s</td>
                        </tr>
                    @else
                        <tr class="hover:bg-shell/40 transition-colors">
                            <td class="px-6 py-3.5 font-mono text-xs text-primary-500 font-bold">A</td>
                            <td class="px-6 py-3.5 font-mono text-xs font-semibold">@</td>
                            <td class="px-6 py-3.5 font-mono text-xs text-muted">{{ $domain->account->server->private_ip }}</td>
                            <td class="px-6 py-3.5 text-xs text-muted">Auto / 300s</td>
                        </tr>
                        <tr class="hover:bg-shell/40 transition-colors">
                            <td class="px-6 py-3.5 font-mono text-xs text-primary-500 font-bold">CNAME</td>
                            <td class="px-6 py-3.5 font-mono text-xs font-semibold">www</td>
                            <td class="px-6 py-3.5 font-mono text-xs text-muted">{{ $domain->domain }}.</td>
                            <td class="px-6 py-3.5 text-xs text-muted">Auto / 300s</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <!-- Troubleshooting Guide -->
    <x-ui.card>
        <h4 class="text-sm font-semibold text-main mb-2">DNS Troubleshooting Tips</h4>
        <ul class="text-xs text-muted space-y-1.5 list-disc list-inside">
            <li><strong>Cloudflare Users:</strong> If using Cloudflare proxy mode (Orange Cloud), SSL certificates should be set to "Full" or "Full (Strict)".</li>
            <li><strong>Propagation Time:</strong> Changes made at your DNS registrar can take between 5 minutes and 24 hours to spread globally depending on your previous TTL.</li>
            <li><strong>CAA Records:</strong> If you use CAA DNS records, ensure <span class="font-mono text-main">letsencrypt.org</span> is authorized to issue certificates for your domain.</li>
        </ul>
    </x-ui.card>
</div>
