<div class="max-w-3xl mx-auto">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">Add Domain / Website</h2>
            <p class="text-sm text-slate-400 mt-1">Deploy an addon domain or subdomain with a customized Nginx profile.</p>
        </div>
        <a href="{{ route('domains.index') }}" class="text-sm text-slate-400 hover:text-white transition">
            &larr; Back to Domains
        </a>
    </div>

    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-sm">
        <form wire:submit="save" class="space-y-6">
            <!-- Account Selector -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Target Hosting Account</label>
                <select wire:model.live="account_id" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm p-3 border focus:ring-1 focus:ring-indigo-500">
                    @foreach ($accounts as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->primary_domain }} (User: {{ $acc->linux_username }})</option>
                    @endforeach
                </select>
                @error('account_id') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
            </div>

            <!-- Domain Name & Type -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Domain Name</label>
                    <input type="text" wire:model.live.debounce.300ms="domain" placeholder="e.g. blog.example.com or mynewsite.org"
                        class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm p-3 border focus:ring-1 focus:ring-indigo-500 font-mono">
                    @error('domain') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Domain Type</label>
                    <select wire:model.live="type" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm p-3 border focus:ring-1 focus:ring-indigo-500">
                        <option value="addon">Addon Domain</option>
                        <option value="subdomain">Subdomain</option>
                    </select>
                    @error('type') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>
            </div>

            <!-- Document Root -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Document Root</label>
                <input type="text" wire:model="document_root"
                    class="w-full rounded-lg bg-slate-800 border-slate-700 text-white font-mono text-sm p-3 border focus:ring-1 focus:ring-indigo-500">
                <p class="text-xs text-slate-500 mt-1">Directory must reside inside account home directory.</p>
                @error('document_root') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
            </div>

            <!-- App Profile & PHP Version -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Application Profile</label>
                    <select wire:model="app_profile" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm p-3 border focus:ring-1 focus:ring-indigo-500">
                        <option value="php-generic">PHP Generic (Standard Web)</option>
                        <option value="laravel">Laravel Framework (public/ routing, security rules)</option>
                        <option value="wordpress">WordPress (Permalinks, upload protection, xmlrpc lock)</option>
                        <option value="static-spa">Static / SPA (HTML/JS, no PHP, 30d asset cache)</option>
                    </select>
                    @error('app_profile') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">PHP Version</label>
                    <select wire:model="php_version" class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm p-3 border focus:ring-1 focus:ring-indigo-500 font-mono">
                        <option value="8.4">PHP 8.4</option>
                        <option value="8.3">PHP 8.3 (Recommended)</option>
                        <option value="8.2">PHP 8.2</option>
                        <option value="8.1">PHP 8.1</option>
                    </select>
                    @error('php_version') <span class="text-xs text-rose-400 block mt-1">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="border-t border-slate-800 pt-6 flex items-center justify-end gap-3">
                <a href="{{ route('domains.index') }}" class="px-4 py-2.5 rounded-lg border border-slate-700 text-xs font-medium text-slate-300 hover:text-white transition">
                    Cancel
                </a>
                <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-xs font-semibold text-white shadow-sm transition">
                    Create Virtual Host &amp; Deploy
                </button>
            </div>
        </form>
    </div>
</div>

