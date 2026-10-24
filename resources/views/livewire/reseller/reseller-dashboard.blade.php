<div>
    <!-- Header -->
    <x-layout.page-header 
        title="Reseller Control Center" 
        subtitle="Manage your customer accounts, custom hosting packages, and white-label branding."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button wire:click="$set('showCustomerModal', true)" variant="primary" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                    </svg>
                    Add Customer
                </x-ui.button>
                <x-ui.button wire:click="$set('showPackageModal', true)" variant="outline" size="sm">
                    Create Package
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Status Alerts -->
    @if (session()->has('success'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('success') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="mb-6 rounded-[20px] bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-500 dark:text-rose-400">
            {{ session('error') }}
        </div>
    @endif

    <!-- Resource Pool Allocation Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Accounts Pool -->
        <x-ui.card>
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">Account Quota</span>
                <span class="text-xs font-mono font-bold text-primary-500">{{ round(($usedAccounts / max(1, $profile->max_accounts)) * 100, 1) }}%</span>
            </div>
            <div class="flex items-baseline gap-2 mb-3">
                <span class="text-2xl font-bold text-main font-mono">{{ $usedAccounts }}</span>
                <span class="text-xs text-muted font-mono">/ {{ $profile->max_accounts }} max</span>
            </div>
            <div class="w-full bg-shell rounded-full h-2 overflow-hidden border border-border">
                <div class="bg-primary-500 h-2 rounded-full" style="width: {{ min(100, ($usedAccounts / max(1, $profile->max_accounts)) * 100) }}%"></div>
            </div>
        </x-ui.card>

        <!-- Storage Pool -->
        <x-ui.card>
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">Storage Pool</span>
                <span class="text-xs font-mono font-bold text-emerald-500">{{ round(($usedStorage / max(1, $profile->max_storage_mb)) * 100, 1) }}%</span>
            </div>
            <div class="flex items-baseline gap-2 mb-3">
                <span class="text-2xl font-bold text-main font-mono">{{ round($usedStorage / 1024, 1) }} GB</span>
                <span class="text-xs text-muted font-mono">/ {{ round($profile->max_storage_mb / 1024, 1) }} GB max</span>
            </div>
            <div class="w-full bg-shell rounded-full h-2 overflow-hidden border border-border">
                <div class="bg-emerald-500 h-2 rounded-full" style="width: {{ min(100, ($usedStorage / max(1, $profile->max_storage_mb)) * 100) }}%"></div>
            </div>
        </x-ui.card>

        <!-- Bandwidth Pool -->
        <x-ui.card>
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">Bandwidth Pool</span>
                <span class="text-xs font-mono font-bold text-amber-500">{{ round(($usedBandwidth / max(1, $profile->max_bandwidth_gb)) * 100, 1) }}%</span>
            </div>
            <div class="flex items-baseline gap-2 mb-3">
                <span class="text-2xl font-bold text-main font-mono">{{ $usedBandwidth }} GB</span>
                <span class="text-xs text-muted font-mono">/ {{ $profile->max_bandwidth_gb }} GB max</span>
            </div>
            <div class="w-full bg-shell rounded-full h-2 overflow-hidden border border-border">
                <div class="bg-amber-500 h-2 rounded-full" style="width: {{ min(100, ($usedBandwidth / max(1, $profile->max_bandwidth_gb)) * 100) }}%"></div>
            </div>
        </x-ui.card>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex gap-2 mb-6">
        <button wire:click="$set('activeTab', 'overview')" class="px-4 py-2 rounded-full text-xs font-semibold transition cursor-pointer {{ $activeTab === 'overview' ? 'bg-primary-500 text-white shadow-xs' : 'bg-card border border-border text-muted hover:text-main' }}">
            Customers ({{ $customers->count() }})
        </button>
        <button wire:click="$set('activeTab', 'packages')" class="px-4 py-2 rounded-full text-xs font-semibold transition cursor-pointer {{ $activeTab === 'packages' ? 'bg-primary-500 text-white shadow-xs' : 'bg-card border border-border text-muted hover:text-main' }}">
            Custom Packages ({{ $packages->count() }})
        </button>
        <button wire:click="$set('activeTab', 'branding')" class="px-4 py-2 rounded-full text-xs font-semibold transition cursor-pointer {{ $activeTab === 'branding' ? 'bg-primary-500 text-white shadow-xs' : 'bg-card border border-border text-muted hover:text-main' }}">
            White-Label Branding
        </button>
    </div>

    <!-- Customers Tab -->
    @if ($activeTab === 'overview')
        <x-ui.card class="p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                            <th class="py-3.5 px-6">Customer</th>
                            <th class="py-3.5 px-6">Email</th>
                            <th class="py-3.5 px-6">Accounts</th>
                            <th class="py-3.5 px-6">Status</th>
                            <th class="py-3.5 px-6">Created At</th>
                            <th class="py-3.5 px-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border font-mono text-main">
                        @forelse ($customers as $c)
                            <tr class="hover:bg-shell/40 transition">
                                <td class="px-6 py-4 font-sans font-bold text-main">{{ $c->name }}</td>
                                <td class="px-6 py-4 text-muted">{{ $c->email }}</td>
                                <td class="px-6 py-4">{{ $c->accounts_count }} account(s)</td>
                                <td class="px-6 py-4 font-sans">
                                    <x-ui.chip :variant="$c->status->value === 'active' ? 'success' : 'danger'" size="sm">
                                        {{ $c->status->value }}
                                    </x-ui.chip>
                                </td>
                                <td class="px-6 py-4 font-sans text-muted">{{ $c->created_at?->format('Y-m-d') }}</td>
                                <td class="px-6 py-4 text-right font-sans">
                                    <button wire:click="toggleCustomerStatus({{ $c->id }})" class="text-xs font-medium cursor-pointer {{ $c->status->value === 'active' ? 'text-rose-500 hover:text-rose-600' : 'text-emerald-500 hover:text-emerald-600' }}">
                                        {{ $c->status->value === 'active' ? 'Suspend' : 'Activate' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-muted font-sans text-xs">No customers registered under your reseller account. Click "Add Customer" to begin.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    <!-- Packages Tab -->
    @if ($activeTab === 'packages')
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @forelse ($packages as $pkg)
                <x-ui.card>
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-base font-bold text-main">{{ $pkg->name }}</h4>
                        <span class="text-xs font-mono text-primary-500 font-medium">{{ $pkg->accounts_count }} users</span>
                    </div>
                    <dl class="space-y-2 text-xs font-mono text-main">
                        <div class="flex justify-between">
                            <dt class="text-muted">Disk Quota:</dt>
                            <dd>{{ round($pkg->storage_mb / 1024, 1) }} GB</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted">Bandwidth:</dt>
                            <dd>{{ $pkg->bandwidth_gb }} GB</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted">Websites:</dt>
                            <dd>{{ $pkg->max_websites }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted">PHP Workers:</dt>
                            <dd>{{ $pkg->php_workers }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            @empty
                <div class="md:col-span-3">
                    <x-ui.card class="p-8 text-center text-muted text-xs">
                        No custom packages created. Click "Create Package" to define tailored hosting tiers within your quota.
                    </x-ui.card>
                </div>
            @endforelse
        </div>
    @endif

    <!-- Branding Tab -->
    @if ($activeTab === 'branding')
        <x-ui.card class="max-w-xl">
            <h3 class="text-base font-bold text-main mb-1">White-Label Branding Settings</h3>
            <p class="text-xs text-muted mb-6">Customize the brand identity your customers see when logging in.</p>

            <form wire:submit.prevent="saveBranding" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Company / Brand Name</label>
                    <x-ui.input type="text" wire:model="company_name" />
                    @error('company_name') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Custom Panel Domain (Optional)</label>
                    <x-ui.input type="text" wire:model="custom_domain" placeholder="panel.myhosting.com" class="font-mono" />
                    @error('custom_domain') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-muted mb-1.5">Customer Support Email</label>
                    <x-ui.input type="email" wire:model="support_email" />
                    @error('support_email') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary" size="sm">
                        Save White-Label Branding
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <!-- Add Customer Modal -->
    @if ($showCustomerModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-main mb-2">Create Customer Account</h3>
                <form wire:submit.prevent="addCustomer" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Customer Full Name</label>
                        <x-ui.input type="text" wire:model="customer_name" />
                        @error('customer_name') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Email Address</label>
                        <x-ui.input type="email" wire:model="customer_email" />
                        @error('customer_email') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Initial Password</label>
                        <x-ui.input type="password" wire:model="customer_password" />
                        @error('customer_password') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                        <x-ui.button type="button" wire:click="$set('showCustomerModal', false)" variant="ghost" size="sm">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">Create Customer</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Create Package Modal -->
    @if ($showPackageModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-lg w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-main mb-1">Create Custom Package</h3>
                <p class="text-xs text-muted mb-4">Must stay within your available resource pool limits.</p>

                <form wire:submit.prevent="createPackage" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Package Name</label>
                        <x-ui.input type="text" wire:model="pkg_name" placeholder="e.g. Starter Tier" />
                        @error('pkg_name') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Storage (MB)</label>
                            <x-ui.input type="number" wire:model="pkg_storage_mb" class="font-mono" />
                            @error('pkg_storage_mb') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Bandwidth (GB)</label>
                            <x-ui.input type="number" wire:model="pkg_bandwidth_gb" class="font-mono" />
                            @error('pkg_bandwidth_gb') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Websites</label>
                            <x-ui.input type="number" wire:model="pkg_max_websites" class="font-mono" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Databases</label>
                            <x-ui.input type="number" wire:model="pkg_max_databases" class="font-mono" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">PHP Workers</label>
                            <x-ui.input type="number" wire:model="pkg_php_workers" class="font-mono" />
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                        <x-ui.button type="button" wire:click="$set('showPackageModal', false)" variant="ghost" size="sm">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">Save Package</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
