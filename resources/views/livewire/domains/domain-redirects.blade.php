<div>
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('domains.index') }}" class="text-xs text-indigo-400 hover:text-indigo-300 transition">&larr; Back to Domains</a>
            </div>
            <h2 class="text-2xl font-bold text-white tracking-tight">Redirects &amp; HTTPS: {{ $domain->domain }}</h2>
            <p class="text-sm text-slate-400 mt-1">Configure canonical host rules, forced SSL/HTTPS upgrades, and custom URL path redirects.</p>
        </div>
    </div>

    @if (session()->has('status'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Settings Form (Left Column) -->
        <div class="lg:col-span-1 bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
            <h3 class="text-base font-semibold text-white mb-4">Canonical &amp; SSL Settings</h3>
            
            <form wire:submit.prevent="saveSettings" class="space-y-5">
                <!-- Force HTTPS -->
                <div class="flex items-start">
                    <div class="flex items-center h-5">
                        <input id="force_https" type="checkbox" wire:model="force_https"
                            class="w-4 h-4 rounded border-slate-700 bg-slate-800 text-indigo-600 focus:ring-indigo-500">
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="force_https" class="font-medium text-white">Force HTTPS</label>
                        <p class="text-slate-400 text-xs mt-0.5">Redirect all plain HTTP requests automatically to HTTPS (301).</p>
                    </div>
                </div>

                <!-- WWW Redirection -->
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-2">Canonical WWW Rule</label>
                    <select wire:model="www_redirect" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                        <option value="none">None (Serve both root and www)</option>
                        <option value="force_www">Force WWW (example.com &rarr; www.example.com)</option>
                        <option value="strip_www">Strip WWW (www.example.com &rarr; example.com)</option>
                    </select>
                    <p class="text-[11px] text-slate-500 mt-1">Applies atomic Nginx return 301 directives before regular application routing.</p>
                </div>

                <div class="pt-2">
                    <button type="submit"
                        class="w-full py-2 px-4 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm focus:outline-none transition">
                        Save SSL &amp; Canonical Rules
                    </button>
                </div>
            </form>
        </div>

        <!-- Custom URL Redirects (Right Column) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Add Redirect Form -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <h3 class="text-base font-semibold text-white mb-4">Add Custom URL Redirect</h3>
                
                <form wire:submit.prevent="addRedirect" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-medium text-slate-400 mb-1">Source Path</label>
                        <input type="text" wire:model="newSource" placeholder="/old-path"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                        @error('newSource') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-5">
                        <label class="block text-xs font-medium text-slate-400 mb-1">Target URL / Path</label>
                        <input type="text" wire:model="newTarget" placeholder="https://external.com or /new-path"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                        @error('newTarget') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-3">
                        <label class="block text-xs font-medium text-slate-400 mb-1">Status Code</label>
                        <select wire:model="newStatusCode" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                            <option value="301">301 (Permanent)</option>
                            <option value="302">302 (Temporary)</option>
                        </select>
                        @error('newStatusCode') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-12 flex justify-end pt-2">
                        <button type="submit"
                            class="py-2 px-4 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm focus:outline-none transition">
                            + Add Redirect Rule
                        </button>
                    </div>
                </form>
            </div>

            <!-- Existing Rules Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
                <div class="px-6 py-4 border-b border-slate-800">
                    <h3 class="text-sm font-semibold text-white">Active URL Redirect Rules ({{ $redirects->count() }})</h3>
                </div>
                <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
                    <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                        <tr>
                            <th scope="col" class="px-6 py-3">Source Path</th>
                            <th scope="col" class="px-6 py-3">Target</th>
                            <th scope="col" class="px-6 py-3">Code</th>
                            <th scope="col" class="px-6 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-300">
                        @forelse ($redirects as $rule)
                            <tr class="hover:bg-slate-800/30 transition-colors">
                                <td class="px-6 py-3 font-mono text-xs text-white">
                                    {{ $rule->source_path }}
                                </td>
                                <td class="px-6 py-3 font-mono text-xs text-slate-300 truncate max-w-xs">
                                    {{ $rule->target_url }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $rule->status_code === 301 ? 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' : 'bg-amber-500/10 text-amber-400 border border-amber-500/20' }}">
                                        {{ $rule->status_code }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-xs">
                                    <button wire:click="deleteRedirect({{ $rule->id }})" wire:confirm="Remove this redirect rule?"
                                        class="text-rose-400 hover:text-rose-300 font-medium transition">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-slate-500 text-sm">
                                    No custom path redirect rules configured yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

