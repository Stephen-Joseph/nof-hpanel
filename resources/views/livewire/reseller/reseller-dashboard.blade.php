<div>
    <!-- Header -->
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">Reseller Control Center</h2>
            <p class="text-sm text-slate-400 mt-1">Manage your customer accounts, custom hosting packages, and white-label branding.</p>
        </div>
        <div class="mt-4 sm:mt-0 flex items-center gap-3">
            <button wire:click="$set('showCustomerModal', true)" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                </svg>
                Add Customer
            </button>
            <button wire:click="$set('showPackageModal', true)" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold border border-slate-700 transition">
                Create Package
            </button>
        </div>
    </div>

    <!-- Status Alerts -->
    @if (session()->has('success'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400">
            {{ session('success') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="mb-6 rounded-lg bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-400">
            {{ session('error') }}
        </div>
    @endif

    <!-- Resource Pool Allocation Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Accounts Pool -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Account Quota</span>
                <span class="text-xs font-mono font-bold text-indigo-400">{{ round(($usedAccounts / max(1, $profile->max_accounts)) * 100, 1) }}%</span>
            </div>
            <div class="flex items-baseline gap-2 mb-3">
                <span class="text-2xl font-bold text-white font-mono">{{ $usedAccounts }}</span>
                <span class="text-xs text-slate-400 font-mono">/ {{ $profile->max_accounts }} max</span>
            </div>
            <div class="w-full bg-slate-950 rounded-full h-2 overflow-hidden">
                <div class="bg-indigo-500 h-2 rounded-full" style="width: {{ min(100, ($usedAccounts / max(1, $profile->max_accounts)) * 100) }}%"></div>
            </div>
        </div>

        <!-- Storage Pool -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Storage Pool</span>
                <span class="text-xs font-mono font-bold text-emerald-400">{{ round(($usedStorage / max(1, $profile->max_storage_mb)) * 100, 1) }}%</span>
            </div>
            <div class="flex items-baseline gap-2 mb-3">
                <span class="text-2xl font-bold text-white font-mono">{{ round($usedStorage / 1024, 1) }} GB</span>
                <span class="text-xs text-slate-400 font-mono">/ {{ round($profile->max_storage_mb / 1024, 1) }} GB max</span>
            </div>
            <div class="w-full bg-slate-950 rounded-full h-2 overflow-hidden">
                <div class="bg-emerald-500 h-2 rounded-full" style="width: {{ min(100, ($usedStorage / max(1, $profile->max_storage_mb)) * 100) }}%"></div>
            </div>
        </div>

        <!-- Bandwidth Pool -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Bandwidth Pool</span>
                <span class="text-xs font-mono font-bold text-amber-400">{{ round(($usedBandwidth / max(1, $profile->max_bandwidth_gb)) * 100, 1) }}%</span>
            </div>
            <div class="flex items-baseline gap-2 mb-3">
                <span class="text-2xl font-bold text-white font-mono">{{ $usedBandwidth }} GB</span>
                <span class="text-xs text-slate-400 font-mono">/ {{ $profile->max_bandwidth_gb }} GB max</span>
            </div>
            <div class="w-full bg-slate-950 rounded-full h-2 overflow-hidden">
                <div class="bg-amber-500 h-2 rounded-full" style="width: {{ min(100, ($usedBandwidth / max(1, $profile->max_bandwidth_gb)) * 100) }}%"></div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="border-b border-slate-800 mb-6 flex gap-4">
        <button wire:click="$set('activeTab', 'overview')" class="pb-3 text-xs font-semibold border-b-2 transition {{ $activeTab === 'overview' ? 'border-indigo-500 text-white' : 'border-transparent text-slate-400 hover:text-white' }}">
            Customers ({{ $customers->count() }})
        </button>
        <button wire:click="$set('activeTab', 'packages')" class="pb-3 text-xs font-semibold border-b-2 transition {{ $activeTab === 'packages' ? 'border-indigo-500 text-white' : 'border-transparent text-slate-400 hover:text-white' }}">
            Custom Packages ({{ $packages->count() }})
        </button>
        <button wire:click="$set('activeTab', 'branding')" class="pb-3 text-xs font-semibold border-b-2 transition {{ $activeTab === 'branding' ? 'border-indigo-500 text-white' : 'border-transparent text-slate-400 hover:text-white' }}">
            White-Label Branding
        </button>
    </div>

    <!-- Customers Tab -->
    @if ($activeTab === 'overview')
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800 text-left text-xs">
                    <thead class="bg-slate-950 text-slate-400 uppercase tracking-wider font-semibold">
                        <tr>
                            <th class="px-6 py-3.5">Customer</th>
                            <th class="px-6 py-3.5">Email</th>
                            <th class="px-6 py-3.5">Accounts</th>
                            <th class="px-6 py-3.5">Status</th>
                            <th class="px-6 py-3.5">Created At</th>
                            <th class="px-6 py-3.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 font-mono text-slate-300">
                        @forelse ($customers as $c)
                            <tr class="hover:bg-slate-800/30 transition">
                                <td class="px-6 py-4 font-sans font-bold text-white">{{ $c->name }}</td>
                                <td class="px-6 py-4">{{ $c->email }}</td>
                                <td class="px-6 py-4">{{ $c->accounts_count }} account(s)</td>
                                <td class="px-6 py-4 font-sans">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold {{ $c->status->value === 'active' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20' }}">
                                        {{ $c->status->value }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 font-sans text-slate-400">{{ $c->created_at?->format('Y-m-d') }}</td>
                                <td class="px-6 py-4 text-right font-sans">
                                    <button wire:click="toggleCustomerStatus({{ $c->id }})" class="text-xs font-medium {{ $c->status->value === 'active' ? 'text-rose-400 hover:text-rose-300' : 'text-emerald-400 hover:text-emerald-300' }}">
                                        {{ $c->status->value === 'active' ? 'Suspend' : 'Activate' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-slate-500 font-sans">No customers registered under your reseller account. Click "Add Customer" to begin.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Packages Tab -->
    @if ($activeTab === 'packages')
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @forelse ($packages as $pkg)
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-base font-bold text-white">{{ $pkg->name }}</h4>
                        <span class="text-xs font-mono text-indigo-400">{{ $pkg->accounts_count }} users</span>
                    </div>
                    <dl class="space-y-2 text-xs font-mono text-slate-300">
                        <div class="flex justify-between">
                            <dt class="text-slate-400">Disk Quota:</dt>
                            <dd>{{ round($pkg->storage_mb / 1024, 1) }} GB</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-400">Bandwidth:</dt>
                            <dd>{{ $pkg->bandwidth_gb }} GB</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-400">Websites:</dt>
                            <dd>{{ $pkg->max_websites }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-400">PHP Workers:</dt>
                            <dd>{{ $pkg->php_workers }}</dd>
                        </div>
                    </dl>
                </div>
            @empty
                <div class="md:col-span-3 bg-slate-900 border border-slate-800 rounded-xl p-8 text-center text-slate-500 text-xs">
                    No custom packages created. Click "Create Package" to define tailored hosting tiers within your quota.
                </div>
            @endforelse
        </div>
    @endif

    <!-- Branding Tab -->
    @if ($activeTab === 'branding')
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 max-w-xl">
            <h3 class="text-base font-bold text-white mb-2">White-Label Branding Settings</h3>
            <p class="text-xs text-slate-400 mb-6">Customize the brand identity your customers see when logging in.</p>

            <form wire:submit.prevent="saveBranding" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Company / Brand Name</label>
                    <input type="text" wire:model="company_name" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white text-xs focus:ring-indigo-500 focus:border-indigo-500" />
                    @error('company_name') <span class="text-rose-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Custom Panel Domain (Optional)</label>
                    <input type="text" wire:model="custom_domain" placeholder="panel.myhosting.com" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white font-mono text-xs focus:ring-indigo-500 focus:border-indigo-500" />
                    @error('custom_domain') <span class="text-rose-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Customer Support Email</label>
                    <input type="email" wire:model="support_email" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white text-xs focus:ring-indigo-500 focus:border-indigo-500" />
                    @error('support_email') <span class="text-rose-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div class="pt-2">
                    <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm transition">
                        Save White-Label Branding
                    </button>
                </div>
            </form>
        </div>
    @endif

    <!-- Add Customer Modal -->
    @if ($showCustomerModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/80 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-white mb-2">Create Customer Account</h3>
                <form wire:submit.prevent="addCustomer" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Customer Full Name</label>
                        <input type="text" wire:model="customer_name" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white text-xs" />
                        @error('customer_name') <span class="text-rose-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Email Address</label>
                        <input type="email" wire:model="customer_email" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white text-xs" />
                        @error('customer_email') <span class="text-rose-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Initial Password</label>
                        <input type="password" wire:model="customer_password" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white text-xs" />
                        @error('customer_password') <span class="text-rose-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
                        <button type="button" wire:click="$set('showCustomerModal', false)" class="px-4 py-2 rounded-lg border border-slate-700 text-slate-300 text-xs">Cancel</button>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold">Create Customer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Create Package Modal -->
    @if ($showPackageModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/80 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-lg w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-white mb-2">Create Custom Package</h3>
                <p class="text-xs text-slate-400 mb-4">Must stay within your available resource pool limits.</p>

                <form wire:submit.prevent="createPackage" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Package Name</label>
                        <input type="text" wire:model="pkg_name" placeholder="e.g. Starter Tier" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white text-xs" />
                        @error('pkg_name') <span class="text-rose-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Storage (MB)</label>
                            <input type="number" wire:model="pkg_storage_mb" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white font-mono text-xs" />
                            @error('pkg_storage_mb') <span class="text-rose-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Bandwidth (GB)</label>
                            <input type="number" wire:model="pkg_bandwidth_gb" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white font-mono text-xs" />
                            @error('pkg_bandwidth_gb') <span class="text-rose-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Websites</label>
                            <input type="number" wire:model="pkg_max_websites" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white font-mono text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Databases</label>
                            <input type="number" wire:model="pkg_max_databases" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white font-mono text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">PHP Workers</label>
                            <input type="number" wire:model="pkg_php_workers" class="block w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-white font-mono text-xs" />
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
                        <button type="button" wire:click="$set('showPackageModal', false)" class="px-4 py-2 rounded-lg border border-slate-700 text-slate-300 text-xs">Cancel</button>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold">Save Package</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

