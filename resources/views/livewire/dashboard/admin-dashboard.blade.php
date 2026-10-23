<div class="space-y-6">
    <!-- Header -->
    <x-layout.page-header
        title="Dashboard"
        subtitle="Manage, monitor, and provision your cloud infrastructure with ease."
    >
        <x-slot:actions>
            <x-ui.button variant="primary" onclick="window.location='{{ route('servers.index') }}'">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>Manage Nodes</span>
            </x-ui.button>

            <x-ui.button variant="outline" onclick="window.location='{{ route('admin.audit-logs') }}'">
                Audit Trail
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Context Subline for Tests & Root Info -->
    <div class="text-xs text-[var(--text-muted)] -mt-3 pb-2 flex flex-wrap items-center gap-2">
        <span class="font-bold text-[var(--text)]">System Administration</span>
        <span>&middot;</span>
        <span>Fleet: <strong class="text-[var(--text)]">{{ $totalServers }} Server Nodes</strong></span>
        <span>&middot;</span>
        <span>Accounts: <strong class="text-[var(--text)]">{{ $totalAccounts }} Total Accounts</strong></span>
        <span>&middot;</span>
        <x-ui.chip variant="primary" dot size="sm">Root Controller</x-ui.chip>
    </div>

    <!-- Row 1: Four Stat Tiles (Tile 1 Highlighted with Primary Gradient) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
        <!-- Tile 1: Server Nodes (HIGHLIGHTED) -->
        <x-ui.stat-tile
            title="Server Nodes"
            :value="$totalServers"
            :trend-value="$activeServers . ' active'"
            :trend-label="$drainingServers . ' draining'"
            :highlighted="true"
            :href="route('servers.index')"
        />

        <!-- Tile 2: Total Accounts -->
        <x-ui.stat-tile
            title="Total Accounts"
            :value="$totalAccounts"
            trend-value="+{{ $totalAccounts }}"
            trend-label="Isolated Linux users"
            :highlighted="false"
            :href="route('accounts.index')"
        />

        <!-- Tile 3: Total Domains -->
        <x-ui.stat-tile
            title="Running Projects"
            :value="$totalDomains"
            trend-value="{{ $totalDomains }}"
            trend-label="Virtual hosts online"
            :highlighted="false"
            :href="route('domains.index')"
        />

        <!-- Tile 4: Packages & Quotas -->
        <x-ui.stat-tile
            title="Pending Project"
            :value="$totalPackages"
            trend-value="{{ $totalPackages }}"
            trend-label="Resource packages"
            :highlighted="false"
            :href="route('packages.index')"
        />
    </div>

    <!-- Row 2: Analytics Chart (Wide), Reminder Card, and Server Nodes List Card -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6">
        <!-- 1. Wide Chart Card (Capsule Bars) -->
        <div class="lg:col-span-6">
            <x-ui.capsule-bars
                title="Project Analytics"
                subtitle="Aggregated cluster network traffic across all nodes"
            />
        </div>

        <!-- 2. Reminders Card -->
        <div class="sm:col-span-6 lg:col-span-3">
            <x-ui.card title="Reminders" class="h-full flex flex-col justify-between">
                <div>
                    <h4 class="text-lg font-extrabold text-[var(--text)] tracking-tight leading-snug">
                        Fleet Agent Health Check
                    </h4>
                    <p class="text-xs text-[var(--text-muted)] mt-1.5 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-[var(--text-muted)] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Interval: 15 min telemetry sync</span>
                    </p>
                </div>

                <div class="pt-6">
                    <a
                        href="{{ route('admin.jobs.index') }}"
                        class="inline-flex items-center justify-center w-full h-10 px-5 rounded-full bg-gradient-primary text-white font-semibold text-xs tracking-wide shadow-xs hover:opacity-95 transition-all gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span>Start Meeting</span>
                    </a>
                </div>
            </x-ui.card>
        </div>

        <!-- 3. Server List Card -->
        <div class="sm:col-span-6 lg:col-span-3">
            <x-ui.card title="Project" class="h-full">
                <x-slot:headerActions>
                    <a
                        href="{{ route('servers.create') }}"
                        class="inline-flex items-center px-3 py-1 rounded-full border border-[var(--border)] text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg-shell)] transition"
                    >
                        + New
                    </a>
                </x-slot:headerActions>

                <div class="space-y-3.5">
                    @forelse ($servers as $node)
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-[var(--text)] truncate">
                                    {{ $node->name }}
                                </p>
                                <p class="text-[11px] text-[var(--text-muted)] truncate">
                                    {{ $node->ip_address }} &middot; <span class="font-mono">{{ $node->accounts_count }} accts</span>
                                </p>
                            </div>
                        </div>
                    @empty
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-500/10 text-blue-600 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-[var(--text)] truncate">Primary Node</p>
                                <p class="text-[11px] text-[var(--text-muted)]">127.0.0.1 &middot; Active</p>
                            </div>
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>

    <!-- Row 3: Team Collaboration, Progress Gauge, and Time Tracker -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6">
        <!-- 1. Operations List Card -->
        <div class="lg:col-span-5">
            <x-ui.card title="Team Collaboration">
                <x-slot:headerActions>
                    <a
                        href="{{ route('admin.audit-logs') }}"
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
                :percentage="41"
                label="Project Ended"
                :completed="25"
                :in-progress="16"
                :pending="59"
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
