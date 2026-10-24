<div class="max-w-3xl mx-auto space-y-6">
    <x-layout.page-header
        title="Add Domain / Website"
        subtitle="Deploy an addon domain or subdomain with a customized Nginx profile."
    >
        <x-slot:actions>
            <x-ui.button variant="outline" onclick="window.location='{{ route('domains.index') }}'">
                &larr; Back to Domains
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.card>
        <form wire:submit="save" novalidate class="space-y-6">
            <!-- Account Selector -->
            <div>
                <x-ui.select name="account_id" id="account_id" label="Target Hosting Account" wire:model.live="account_id" required>
                    <option value="" disabled {{ is_null($account_id) ? 'selected' : '' }}>Select hosting account...</option>
                    @foreach ($accounts as $acc)
                        <option value="{{ $acc->id }}" {{ (string)$account_id === (string)$acc->id ? 'selected' : '' }}>
                            {{ $acc->primary_domain }} (User: {{ $acc->linux_username }})
                        </option>
                    @endforeach
                </x-ui.select>
                @error('account_id') <span class="text-xs text-rose-500 block mt-1 ml-2">{{ $message }}</span> @enderror
            </div>

            <!-- Domain Name & Type -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <x-ui.input
                        name="domain"
                        label="Domain Name"
                        wire:model.live.debounce.300ms="domain"
                        placeholder="e.g. blog.example.com or mynewsite.org"
                        required
                    />
                    @error('domain') <span class="text-xs text-rose-500 block mt-1 ml-2">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.select name="type" label="Domain Type" wire:model.live="type" required>
                        <option value="addon">Addon Domain</option>
                        <option value="subdomain">Subdomain</option>
                    </x-ui.select>
                    @error('type') <span class="text-xs text-rose-500 block mt-1 ml-2">{{ $message }}</span> @enderror
                </div>
            </div>

            <!-- Document Root -->
            <div>
                <x-ui.input
                    name="document_root"
                    label="Document Root"
                    wire:model="document_root"
                    hint="Directory must reside inside account home directory."
                    required
                />
                @error('document_root') <span class="text-xs text-rose-500 block mt-1 ml-2">{{ $message }}</span> @enderror
            </div>

            <!-- App Profile & PHP Version -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-ui.select name="app_profile" label="Application Profile" wire:model="app_profile" required>
                        <option value="php-generic">PHP Generic (Standard Web)</option>
                        <option value="laravel">Laravel Framework (public/ routing, security rules)</option>
                        <option value="wordpress">WordPress (Permalinks, upload protection, xmlrpc lock)</option>
                        <option value="static-spa">Static / SPA (HTML/JS, no PHP, 30d asset cache)</option>
                    </x-ui.select>
                    @error('app_profile') <span class="text-xs text-rose-500 block mt-1 ml-2">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.select name="php_version" label="PHP Version" wire:model="php_version" required>
                        <option value="8.4">PHP 8.4</option>
                        <option value="8.3">PHP 8.3 (Recommended)</option>
                        <option value="8.2">PHP 8.2</option>
                        <option value="8.1">PHP 8.1</option>
                    </x-ui.select>
                    @error('php_version') <span class="text-xs text-rose-500 block mt-1 ml-2">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="border-t border-[var(--border)] pt-6 flex items-center justify-end gap-3">
                <x-ui.button variant="outline" onclick="window.location='{{ route('domains.index') }}'">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit" variant="primary" loading="save">
                    Create Virtual Host &amp; Deploy
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
