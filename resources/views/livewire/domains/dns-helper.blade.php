<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('domains.index') }}" class="text-xs text-indigo-400 hover:text-indigo-300 transition">&larr; Back to Domains</a>
            </div>
            <h2 class="text-2xl font-bold text-white tracking-tight">DNS Configuration &amp; Verification: {{ $domain->domain }}</h2>
            <p class="text-sm text-slate-400 mt-1">Review required DNS records for your domain and verify live public DNS propagation.</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <button wire:click="checkDns" wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-lg shadow-sm text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 focus:outline-none transition">
                <span wire:loading.remove wire:target="checkDns">Verify DNS Resolution</span>
                <span wire:loading wire:target="checkDns" class="flex items-center gap-1.5">
                    <svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    Checking...
                </span>
            </button>
        </div>
    </div>

    <!-- Live Check Results -->
    @if ($hasChecked)
        <div class="mb-6">
            @if ($checkStatus === 'pointing')
                <div class="rounded-xl bg-emerald-500/10 border border-emerald-500/20 p-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
                        &check;
                    </div>
                    <div>
                        <h4 class="text-sm font-semibold text-emerald-300">DNS Properly Configured</h4>
                        <p class="text-xs text-emerald-400/90 mt-0.5">
                            <strong>{{ $domain->domain }}</strong> resolves to <strong>{{ $resolvedIp }}</strong>, matching your target server (<span class="font-mono">{{ $domain->account->server->name }}</span>). Web traffic and SSL issuance are ready.
                        </p>
                    </div>
                </div>
            @elseif ($checkStatus === 'mismatch')
                <div class="rounded-xl bg-amber-500/10 border border-amber-500/20 p-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center shrink-0">
                        !
                    </div>
                    <div>
                        <h4 class="text-sm font-semibold text-amber-300">DNS IP Mismatch</h4>
                        <p class="text-xs text-amber-400/90 mt-0.5">
                            <strong>{{ $domain->domain }}</strong> resolves to <strong>{{ $resolvedIp }}</strong>, but this server's IP is <strong>{{ $domain->account->server->private_ip }}</strong>.
                            If you recently updated DNS records, please allow up to 24-48 hours for global DNS cache propagation.
                        </p>
                    </div>
                </div>
            @else
                <div class="rounded-xl bg-rose-500/10 border border-rose-500/20 p-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-rose-500/20 text-rose-400 flex items-center justify-center shrink-0">
                        &times;
                    </div>
                    <div>
                        <h4 class="text-sm font-semibold text-rose-300">No DNS Records Found</h4>
                        <p class="text-xs text-rose-400/90 mt-0.5">
                            Could not resolve any A records for <strong>{{ $domain->domain }}</strong>. Please check your domain registrar or authoritative DNS nameserver configuration.
                        </p>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <!-- Recommended DNS Records -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm mb-6">
        <div class="px-6 py-4 border-b border-slate-800 flex justify-between items-center">
            <div>
                <h3 class="text-sm font-semibold text-white">Required DNS Records</h3>
                <p class="text-xs text-slate-400 mt-0.5">Add these records at your DNS registrar or Cloudflare to direct visitors to this server.</p>
            </div>
            <span class="text-xs font-mono text-slate-400">Target Server: {{ $domain->account->server->name }} ({{ $domain->account->server->private_ip }})</span>
        </div>

        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                <tr>
                    <th scope="col" class="px-6 py-3">Type</th>
                    <th scope="col" class="px-6 py-3">Host / Name</th>
                    <th scope="col" class="px-6 py-3">Points To / Value</th>
                    <th scope="col" class="px-6 py-3">TTL</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800 text-slate-300">
                @if ($domain->type->value === 'subdomain')
                    <tr>
                        <td class="px-6 py-3 font-mono text-xs text-indigo-400 font-semibold">A</td>
                        <td class="px-6 py-3 font-mono text-xs text-white">{{ explode('.', $domain->domain)[0] }}</td>
                        <td class="px-6 py-3 font-mono text-xs text-slate-200">{{ $domain->account->server->private_ip }}</td>
                        <td class="px-6 py-3 text-xs text-slate-400">Auto / 300s</td>
                    </tr>
                @else
                    <tr>
                        <td class="px-6 py-3 font-mono text-xs text-indigo-400 font-semibold">A</td>
                        <td class="px-6 py-3 font-mono text-xs text-white">@</td>
                        <td class="px-6 py-3 font-mono text-xs text-slate-200">{{ $domain->account->server->private_ip }}</td>
                        <td class="px-6 py-3 text-xs text-slate-400">Auto / 300s</td>
                    </tr>
                    <tr>
                        <td class="px-6 py-3 font-mono text-xs text-indigo-400 font-semibold">CNAME</td>
                        <td class="px-6 py-3 font-mono text-xs text-white">www</td>
                        <td class="px-6 py-3 font-mono text-xs text-slate-200">{{ $domain->domain }}.</td>
                        <td class="px-6 py-3 text-xs text-slate-400">Auto / 300s</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    <!-- Troubleshooting Guide -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
        <h4 class="text-sm font-semibold text-white mb-2">DNS Troubleshooting Tips</h4>
        <ul class="text-xs text-slate-400 space-y-1.5 list-disc list-inside">
            <li><strong>Cloudflare Users:</strong> If using Cloudflare proxy mode (Orange Cloud), SSL certificates should be set to "Full" or "Full (Strict)".</li>
            <li><strong>Propagation Time:</strong> Changes made at your DNS registrar can take between 5 minutes and 24 hours to spread globally depending on your previous TTL.</li>
            <li><strong>CAA Records:</strong> If you use CAA DNS records, ensure <span class="font-mono text-slate-300">letsencrypt.org</span> is authorized to issue certificates for your domain.</li>
        </ul>
    </div>
</div>

