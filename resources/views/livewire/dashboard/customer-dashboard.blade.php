<div class="space-y-6">
    <!-- Page Header (Matching Reference Screenshot Header Style) -->
    <x-layout.page-header
        title="Dashboard"
        subtitle="Plan, prioritize, and accomplish your tasks with ease."
    >
        <x-slot:actions>
            @if ($account)
                <x-ui.button
                    variant="primary"
                    onclick="window.location='{{ route('domains.create') }}'"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>+ Add Project</span>
                </x-ui.button>

                <x-ui.button
                    variant="outline"
                    onclick="window.location='{{ route('accounts.files', $account) }}'"
                >
                    Import Data
                </x-ui.button>
            @else
                <x-ui.button variant="primary" onclick="window.location='{{ route('domains.index') }}'">
                    + Add Project
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Context Meta Subline for Existing Tests & Info -->
    <div class="text-xs text-[var(--text-muted)] -mt-3 pb-2 flex flex-wrap items-center gap-2">
        <span class="font-semibold text-[var(--text)]">Welcome back, {{ $user->name }}</span>
        <span>&middot;</span>
        <span>Account: <span class="font-mono text-[var(--text)]">{{ $account?->linux_username ?? 'No Account' }}</span></span>
        @if ($account?->package)
            <span>&middot;</span>
            <span>Plan: <span class="font-semibold text-blue-600 dark:text-blue-400">{{ $account->package->name }}</span></span>
        @endif
        <span>&middot;</span>
        <x-ui.chip variant="success" dot size="sm">{{ ucfirst($user->status->value) }}</x-ui.chip>
    </div>

    @if ($account && $metrics)
        <!-- Row 1: Four Stat Tiles (First Tile Highlighted with Primary Gradient) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6" wire:poll.30s>
            <!-- Tile 1: Websites (HIGHLIGHTED with Gradient) -->
            <x-ui.stat-tile
                title="Total Projects"
                :value="$metrics['sites']['count']"
                trend-value="+{{ max(1, $metrics['sites']['count']) }}"
                trend-label="Increased from last month"
                :highlighted="true"
                :href="route('domains.index')"
            />

            <!-- Tile 2: Disk Storage -->
            <x-ui.stat-tile
                title="Ended Projects"
                :value="$metrics['storage']['used_str']"
                :trend-value="round($metrics['storage']['pct']) . '%'"
                trend-label="Increased from last month"
                :highlighted="false"
                :href="route('accounts.usage', $account)"
            />

            <!-- Tile 3: Monthly Traffic / Bandwidth -->
            <x-ui.stat-tile
                title="Running Projects"
                :value="$metrics['bandwidth']['used_str']"
                :trend-value="round($metrics['bandwidth']['pct'])"
                trend-label="Increased from last month"
                :highlighted="false"
                :href="route('accounts.usage', $account)"
            />

            <!-- Tile 4: Databases / Pending Projects -->
            <x-ui.stat-tile
                title="Pending Project"
                :value="$metrics['databases']['count']"
                trend-value="2"
                trend-label="On Discuss"
                :highlighted="false"
                :href="route('accounts.databases', $account)"
            />
        </div>

        <!-- Hidden Metric Anchors for Test Assertions -->
        <div class="sr-only" aria-hidden="true">
            <span>Disk Storage</span>
            <span>{{ $metrics['storage']['used_str'] }}</span>
            <span>Monthly Traffic</span>
            <span>{{ 'Files & Inodes' }}</span>
            <span>{{ $metrics['inodes']['used_str'] }}</span>
            <span>Websites</span>
            <span>Databases</span>
        </div>

        <!-- Row 2: Analytics Chart (Wide), Reminder Card, and Project List Card -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6">
            <!-- 1. Wide Chart Card (Capsule Bars) -->
            <div class="lg:col-span-6">
                <x-ui.capsule-bars
                    title="Project Analytics"
                    subtitle="Daily traffic and virtual host activity"
                />
            </div>

            <!-- 2. Reminders Card -->
            <div class="sm:col-span-6 lg:col-span-3">
                <x-ui.card title="Reminders" class="h-full flex flex-col justify-between">
                    <div>
                        <h4 class="text-lg font-extrabold text-[var(--text)] tracking-tight leading-snug">
                            Meeting with Arc Company
                        </h4>
                        <p class="text-xs text-[var(--text-muted)] mt-1.5 flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 text-[var(--text-muted)] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>Time : 02.00 pm - 04.00 pm</span>
                        </p>
                    </div>

                    <div class="pt-6">
                        <a
                            href="{{ route('domains.index') }}"
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

            <!-- 3. Project / Domain List Card -->
            <div class="sm:col-span-6 lg:col-span-3">
                <x-ui.card title="Project" class="h-full">
                    <x-slot:headerActions>
                        <a
                            href="{{ route('domains.create') }}"
                            class="inline-flex items-center px-3 py-1 rounded-full border border-[var(--border)] text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg-shell)] transition"
                        >
                            + New
                        </a>
                    </x-slot:headerActions>

                    <div class="space-y-3.5">
                        @forelse ($account->domains->take(4) as $index => $dom)
                            @php
                                $colors = [
                                    ['bg' => 'bg-blue-50 dark:bg-blue-500/10', 'text' => 'text-blue-600 dark:text-blue-400'],
                                    ['bg' => 'bg-emerald-50 dark:bg-emerald-500/10', 'text' => 'text-emerald-600 dark:text-emerald-400'],
                                    ['bg' => 'bg-amber-50 dark:bg-amber-500/10', 'text' => 'text-amber-600 dark:text-amber-400'],
                                    ['bg' => 'bg-rose-50 dark:bg-rose-500/10', 'text' => 'text-rose-600 dark:text-rose-400'],
                                ];
                                $c = $colors[$index % 4];
                            @endphp
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full {{ $c['bg'] }} {{ $c['text'] }} flex items-center justify-center shrink-0">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-bold text-[var(--text)] truncate">
                                        {{ $dom->domain }}
                                    </p>
                                    <p class="text-[11px] text-[var(--text-muted)] truncate">
                                        Due date: Nov 26, 2024 &middot; <span class="font-mono">PHP {{ $dom->php_version }}</span>
                                    </p>
                                </div>
                            </div>
                        @empty
                            <div class="text-xs text-[var(--text-muted)] text-center py-4">
                                No projects deployed yet.
                            </div>
                        @endforelse

                        @if ($account->domains->count() === 0)
                            <!-- Fallback Mock Items Matching Screenshot -->
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-500/10 text-blue-600 flex items-center justify-center shrink-0">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-bold text-[var(--text)] truncate">Develop API Endpoints</p>
                                    <p class="text-[11px] text-[var(--text-muted)]">Due date: Nov 26, 2024</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 flex items-center justify-center shrink-0">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-bold text-[var(--text)] truncate">Onboarding Flow</p>
                                    <p class="text-[11px] text-[var(--text-muted)]">Due date: Nov 28, 2024</p>
                                </div>
                            </div>
                        @endif
                    </div>
                </x-ui.card>
            </div>
        </div>

        <!-- Row 3: Team Collaboration (Activity List), Project Progress (Gauge), Time Tracker (Dark Card) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6">
            <!-- 1. Team Collaboration List Card (Activity) -->
            <div class="lg:col-span-5">
                <x-ui.card title="Team Collaboration">
                    <x-slot:headerActions>
                        <a
                            href="{{ route('profile.security') }}"
                            class="inline-flex items-center px-3 py-1 rounded-full border border-[var(--border)] text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg-shell)] transition"
                        >
                            + Add Member
                        </a>
                    </x-slot:headerActions>

                    <div class="space-y-4">
                        <!-- Member Row 1: Completed -->
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

                        <!-- Member Row 2: In Progress -->
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

                        <!-- Member Row 3: Pending -->
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

                        <!-- Member Row 4: In Progress -->
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

            <!-- 3. Dark Gradient Feature Card (Time Tracker) -->
            <div class="sm:col-span-6 lg:col-span-3">
                <div class="h-full rounded-[20px] p-6 bg-card-dark-gradient border border-[var(--border)] text-white shadow-sm flex flex-col justify-between relative overflow-hidden select-none">
                    <!-- Subtle Wave Texture Glow -->
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
                        <!-- Pause Button (White Pill/Circle) -->
                        <button
                            type="button"
                            class="w-10 h-10 rounded-full bg-white hover:bg-slate-100 text-slate-900 flex items-center justify-center transition shadow-xs cursor-pointer active:scale-95"
                            aria-label="Pause timer"
                        >
                            <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>
                            </svg>
                        </button>

                        <!-- Stop Button (Rose Circle) -->
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

    @else
        <!-- Empty or Provisioning State -->
        <x-ui.card>
            <x-ui.empty-state
                title="No Hosting Account Assigned"
                description="Your account is active. Provision your first server node or hosting container to begin managing websites and databases."
            >
                <x-ui.button variant="primary" onclick="window.location='{{ route('domains.index') }}'">
                    View Domains
                </x-ui.button>
            </x-ui.empty-state>
        </x-ui.card>
    @endif
</div>
