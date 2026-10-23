<div>
    <x-layout.page-header 
        title="Redirects &amp; HTTPS: {{ $domain->domain }}" 
        subtitle="Configure canonical host rules, forced SSL/HTTPS upgrades, and custom URL path redirects."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('domains.index') }}" variant="outline" size="sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Domains
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Settings Form (Left Column) -->
        <div class="lg:col-span-1">
            <x-ui.card>
                <h3 class="text-base font-semibold text-main mb-4">Canonical &amp; SSL Settings</h3>
                
                <form wire:submit.prevent="saveSettings" class="space-y-5">
                    <!-- Force HTTPS -->
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input id="force_https" type="checkbox" wire:model="force_https"
                                class="w-4 h-4 rounded-sm border-border bg-card text-primary-500 focus:ring-primary-500">
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="force_https" class="font-medium text-main cursor-pointer">Force HTTPS</label>
                            <p class="text-muted text-xs mt-0.5">Redirect all plain HTTP requests automatically to HTTPS (301).</p>
                        </div>
                    </div>

                    <!-- WWW Redirection -->
                    <div>
                        <label class="block text-xs font-medium text-muted mb-2">Canonical WWW Rule</label>
                        <x-ui.select wire:model="www_redirect">
                            <option value="none">None (Serve both root and www)</option>
                            <option value="force_www">Force WWW (example.com &rarr; www.example.com)</option>
                            <option value="strip_www">Strip WWW (www.example.com &rarr; example.com)</option>
                        </x-ui.select>
                        <p class="text-[11px] text-muted mt-1.5">Applies atomic Nginx return 301 directives before regular application routing.</p>
                    </div>

                    <div class="pt-2">
                        <x-ui.button type="submit" variant="primary" class="w-full">
                            Save SSL &amp; Canonical Rules
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>

        <!-- Custom URL Redirects (Right Column) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Add Redirect Form -->
            <x-ui.card>
                <h3 class="text-base font-semibold text-main mb-4">Add Custom URL Redirect</h3>
                
                <form wire:submit.prevent="addRedirect" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-medium text-muted mb-1.5">Source Path</label>
                        <x-ui.input type="text" wire:model="newSource" placeholder="/old-path" />
                        @error('newSource') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-5">
                        <label class="block text-xs font-medium text-muted mb-1.5">Target URL / Path</label>
                        <x-ui.input type="text" wire:model="newTarget" placeholder="https://external.com or /new-path" />
                        @error('newTarget') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-3">
                        <label class="block text-xs font-medium text-muted mb-1.5">Status Code</label>
                        <x-ui.select wire:model="newStatusCode">
                            <option value="301">301 (Permanent)</option>
                            <option value="302">302 (Temporary)</option>
                        </x-ui.select>
                        @error('newStatusCode') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-12 flex justify-end pt-2">
                        <x-ui.button type="submit" variant="primary" size="sm">
                            + Add Redirect Rule
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <!-- Existing Rules Table -->
            <x-ui.card class="p-0 overflow-hidden">
                <div class="px-6 py-4 border-b border-border">
                    <h3 class="text-sm font-semibold text-main">Active URL Redirect Rules ({{ $redirects->count() }})</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                                <th class="py-3 px-6">Source Path</th>
                                <th class="py-3 px-6">Target</th>
                                <th class="py-3 px-6">Code</th>
                                <th class="py-3 px-6 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-main">
                            @forelse ($redirects as $rule)
                                <tr class="hover:bg-shell/40 transition-colors">
                                    <td class="px-6 py-3 font-mono text-xs font-medium text-main">
                                        {{ $rule->source_path }}
                                    </td>
                                    <td class="px-6 py-3 font-mono text-xs text-muted truncate max-w-xs">
                                        {{ $rule->target_url }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <x-ui.chip :variant="$rule->status_code === 301 ? 'info' : 'warning'" size="sm">
                                            {{ $rule->status_code }}
                                        </x-ui.chip>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right text-xs">
                                        <button wire:click="deleteRedirect({{ $rule->id }})" wire:confirm="Remove this redirect rule?"
                                            class="text-rose-500 hover:text-rose-600 font-medium transition cursor-pointer">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-muted text-xs">
                                        No custom path redirect rules configured yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
