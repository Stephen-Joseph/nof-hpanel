<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">Domains &amp; Websites</h2>
            <p class="text-sm text-slate-400 mt-1">Manage primary, addon, and subdomain websites, document roots, and web server configurations.</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <a href="{{ route('domains.create') }}"
                class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none transition">
                + Add Domain
            </a>
        </div>
    </div>

    @if (session()->has('status'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="mb-6 rounded-lg bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-400">
            {{ session('error') }}
        </div>
    @endif

    <!-- Filters -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Search Domains</label>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Filter by domain name..."
                class="w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1">Filter by Type</label>
            <select wire:model.live="typeFilter" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                <option value="">All Types</option>
                <option value="primary">Primary</option>
                <option value="addon">Addon</option>
                <option value="subdomain">Subdomain</option>
            </select>
        </div>
    </div>

    <!-- Domains Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                <tr>
                    <th scope="col" class="px-6 py-3">Domain</th>
                    <th scope="col" class="px-6 py-3">Account / User</th>
                    <th scope="col" class="px-6 py-3">Type</th>
                    <th scope="col" class="px-6 py-3">Profile</th>
                    <th scope="col" class="px-6 py-3">Document Root</th>
                    <th scope="col" class="px-6 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800 text-slate-300">
                @forelse ($domains as $d)
                    <tr class="hover:bg-slate-800/30 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap font-medium text-white">
                            <a href="http://{{ $d->domain }}" target="_blank" class="hover:text-indigo-400 flex items-center gap-1.5">
                                {{ $d->domain }}
                                <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                            </a>
                            @if($d->force_https)
                                <span class="text-[10px] text-emerald-400 font-mono">HTTPS Enforced</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-slate-400">
                            {{ $d->account->linux_username }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ match($d->type->value) {
                                'primary' => 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20',
                                'addon' => 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20',
                                'subdomain' => 'bg-amber-500/10 text-amber-400 border border-amber-500/20',
                                default => 'bg-slate-800 text-slate-400',
                            } }}">
                                {{ $d->type->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-slate-300">
                            {{ $d->app_profile->label() }} (PHP {{ $d->php_version }})
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-slate-400 truncate max-w-xs">
                            {{ $d->document_root }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs space-x-2">
                            <a href="{{ route('domains.redirects', $d) }}" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded border border-slate-700 transition">
                                Redirects
                            </a>
                            <a href="{{ route('domains.ssl', $d) }}" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded border border-slate-700 transition">
                                SSL
                            </a>
                            <a href="{{ route('domains.dns', $d) }}" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded border border-slate-700 transition">
                                DNS
                            </a>
                            @if($d->type->value !== 'primary')
                                <button wire:click="deleteDomain({{ $d->id }})" wire:confirm="Are you sure you want to delete this domain and its Nginx virtual host?"
                                    class="px-2.5 py-1.5 bg-rose-950/60 hover:bg-rose-900 text-rose-300 rounded border border-rose-800 transition">
                                    Delete
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-slate-500 text-sm">
                            No domains registered yet. Click "+ Add Domain" to get started.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

