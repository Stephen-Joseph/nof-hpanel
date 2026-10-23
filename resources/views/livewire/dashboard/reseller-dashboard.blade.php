<div class="space-y-6">
    <!-- Header -->
    <x-layout.page-header
        title="Dashboard"
        subtitle="Manage client pools, provision hosting accounts, and oversee customer quotas."
    >
        <x-slot:actions>
            <x-ui.button variant="primary" onclick="window.location='{{ route('reseller.index') }}'">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>Reseller Control Center</span>
            </x-ui.button>

            <x-ui.button variant="outline" onclick="window.location='{{ route('accounts.index') }}'">
                Manage Accounts
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Context Subline for Tests & Reseller Info -->
    <div class="text-xs text-[var(--text-muted)] -mt-3 pb-2 flex flex-wrap items-center gap-2">
        <span class="font-bold text-[var(--text)]">Reseller Overview</span>
        <span>&middot;</span>
        <span>Company: <strong class="text-[var(--text)]">{{ $profile?->company_name ?? 'HostCorp Cloud' }}</strong></span>
        <span>&middot;</span>
        <span>Clients: <strong class="text-[var(--text)]">{{ $customersCount }} Customers</strong> &middot; {{ $accountsCount }} Accounts</span>
        <span>&middot;</span>
        <x-ui.chip variant="success" dot size="sm">Reseller Active</x-ui.chip>
    </div>

    <!-- Row 1: Four Stat Tiles (Tile 1 Highlighted with Primary Gradient) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
        <!-- Tile 1: Account Quota (HIGHLIGHTED) -->
        <x-ui.stat-tile
            title="Account Quota"
            :value="$accountsCount . ' / ' . $maxAccounts"
            :trend-value="round($accPct) . '%'"
            trend-label="Allocated accounts"
            :highlighted="true"
            :href="route('reseller.index')"
        />

        <!-- Tile 2: Storage Pool -->
        <x-ui.stat-tile
            title="Storage Pool"
            :value="sprintf('%.1f GB', $storageUsedMb / 1024)"
            :trend-value="round($storagePct) . '%'"
            :trend-label="sprintf('Limit: %.1f GB', $maxStorageMb / 1024)"
            :highlighted="false"
            :href="route('reseller.index')"
        />

        <!-- Tile 3: Bandwidth Pool -->
        <x-ui.stat-tile
            title="Bandwidth Pool"
            :value="sprintf('%.1f GB', $bwUsedMb / 1024)"
            :trend-value="round($bwPct) . '%'"
            :trend-label="sprintf('Pool: %d GB', $maxBwGb)"
            :highlighted="false"
            :href="route('reseller.index')"
        />

        <!-- Tile 4: Active Customers -->
        <x-ui.stat-tile
            title="Pending Project"
            :value="$customersCount"
            trend-value="{{ $customersCount }}"
            trend-label="Managed customers"
            :highlighted="false"
            :href="route('accounts.index')"
        />
    </div>

    <!-- Row 2: Analytics Chart (Wide), Reminder Card, and Customer Accounts List Card -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6">
        <!-- 1. Wide Chart Card (Capsule Bars) -->
        <div class="lg:col-span-6">
            <x-ui.capsule-bars
                title="Project Analytics"
                subtitle="Aggregated customer bandwidth consumption"
            />
        </div>

        <!-- 2. Reminders Card -->
        <div class="sm:col-span-6 lg:col-span-3">
            <x-ui.card title="Reminders" class="h-full flex flex-col justify-between">
                <div>
                    <h4 class="text-lg font-extrabold text-[var(--text)] tracking-tight leading-snug">
                        Monthly Quota Settlement
                    </h4>
                    <p class="text-xs text-[var(--text-muted)] mt-1.5 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-[var(--text-muted)] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Reset scheduled in 8 days</span>
                    </p>
                </div>

                <div class="pt-6">
                    <a
                        href="{{ route('reseller.index') }}"
                        class="inline-flex items-center justify-center w-full h-10 px-5 rounded-full bg-gradient-primary text-white font-semibold text-xs tracking-wide shadow-xs hover:opacity-95 transition-all gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        <span>Start Meeting</span>
                    </a>
                </div>
            </x-ui.card>
        </div>

        <!-- 3. Project / Customer List Card -->
        <div class="sm:col-span-6 lg:col-span-3">
            <x-ui.card title="Project" class="h-full">
                <x-slot:headerActions>
                    <a
                        href="{{ route('accounts.index') }}"
                        class="inline-flex items-center px-3 py-1 rounded-full border border-[var(--border)] text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg-shell)] transition"
                    >
                        + New
                    </a>
                </x-slot:headerActions>

                <div class="space-y-3.5">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-500/10 text-blue-600 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-bold text-[var(--text)] truncate">Client Web Cluster</p>
                            <p class="text-[11px] text-[var(--text-muted)]">Due date: Nov 26, 2024</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-bold text-[var(--text)] truncate">Production Portal</p>
                            <p class="text-[11px] text-[var(--text-muted)]">Due date: Nov 28, 2024</p>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>

    <!-- Row 3: Team Collaboration, Progress Gauge, and Time Tracker -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6">
        <!-- 1. Customer Operations List Card -->
        <div class="lg:col-span-5">
            <x-ui.card title="Team Collaboration">
                <x-slot:headerActions>
                    <a
                        href="{{ route('accounts.index') }}"
                        class="inline-flex items-center px-3 py-1 rounded-full border border-[var(--border)] text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg-shell)] transition"
                    >
                        + Add Member
                    </a>
                </x-slot:headerActions>

                <div class="space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center font-bold text-xs shrink-0">
                                AD
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-[var(--text)] truncate">Alexandra Deff</p>
                                <p class="text-[11px] text-[var(--text-muted)] truncate">Working on Github Project Repository</p>
                            </div>
                        </div>
                        <x-ui.chip variant="success" size="sm">Completed</x-ui.chip>
                    </div>

                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-xs shrink-0">
                                EA
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-[var(--text)] truncate">Edwin Adenike</p>
                                <p class="text-[11px] text-[var(--text-muted)] truncate">Working on Integrate User Authentication System</p>
                            </div>
                        </div>
                        <x-ui.chip variant="info" size="sm">In Progress</x-ui.chip>
                    </div>

                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-xs shrink-0">
                                IO
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-[var(--text)] truncate">Isaac Oluwatemilorun</p>
                                <p class="text-[11px] text-[var(--text-muted)] truncate">Working on Develop Search and Filter Functionality</p>
                            </div>
                        </div>
                        <x-ui.chip variant="warning" size="sm">Pending</x-ui.chip>
                    </div>

                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center font-bold text-xs shrink-0">
                                DO
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-[var(--text)] truncate">David Oshodi</p>
                                <p class="text-[11px] text-[var(--text-muted)] truncate">Working on Responsive Layout for Homepage</p>
                            </div>
                        </div>
                        <x-ui.chip variant="info" size="sm">In Progress</x-ui.chip>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <!-- 2. Semi-Circle Progress Gauge Chart -->
        <div class="sm:col-span-6 lg:col-span-4">
            <x-ui.progress-gauge
                title="Project Progress"
                :percentage="round($storagePct)"
                label="Pool Allocated"
                :completed="round($storagePct * 0.6)"
                :in-progress="round($storagePct * 0.4)"
                :pending="max(0, 100 - round($storagePct))"
            />
        </div>

        <!-- 3. Dark Feature Card (Time Tracker) -->
        <div class="sm:col-span-6 lg:col-span-3">
            <div class="h-full rounded-[20px] p-6 bg-card-dark-gradient border border-[var(--border)] text-white shadow-sm flex flex-col justify-between relative overflow-hidden select-none">
                <div class="absolute -right-8 -top-8 w-36 h-36 bg-blue-500/10 rounded-full blur-2xl pointer-events-none"></div>

                <div class="text-sm font-semibold text-slate-300">
                    Time Tracker
                </div>

                <div class="my-auto py-4 text-center">
                    <div class="text-4xl font-mono font-bold tracking-wider text-white">
                        01:24:08
                    </div>
                </div>

                <div class="flex items-center justify-center gap-3 pt-2">
                    <button
                        type="button"
                        class="w-10 h-10 rounded-full bg-white hover:bg-slate-100 text-slate-900 flex items-center justify-center transition shadow-xs cursor-pointer active:scale-95"
                        aria-label="Pause timer"
                    >
                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
                            <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>
                        </svg>
                    </button>

                    <button
                        type="button"
                        class="w-10 h-10 rounded-full bg-rose-600 hover:bg-rose-500 text-white flex items-center justify-center transition shadow-xs cursor-pointer active:scale-95"
                        aria-label="Stop timer"
                    >
                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
                            <path d="M6 6h12v12H6z"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
