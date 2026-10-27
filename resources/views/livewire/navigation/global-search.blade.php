<div
    x-data="{
        init() {
            window.addEventListener('keydown', (e) => {
                if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                    e.preventDefault();
                    $wire.open();
                    this.$nextTick(() => {
                        this.$refs.searchInput?.focus();
                    });
                }
            });
        }
    }"
    class="w-full max-w-xs sm:max-w-sm"
>
    <!-- Keyboard shortcut button trigger in topbar (Pill-shaped like reference) -->
    <button
        type="button"
        wire:click="open"
        class="w-full h-9 sm:h-10 flex items-center justify-between gap-2 sm:gap-3 px-3 sm:px-4 text-xs text-[var(--text-muted)] bg-[var(--bg-card)] hover:bg-[var(--bg-shell)] border border-[var(--border)] rounded-full transition-colors duration-150 shadow-2xs group cursor-pointer"
        aria-label="Search panel (Ctrl+K)"
    >
        <div class="flex items-center gap-2 min-w-0">
            <svg class="w-4 h-4 text-[var(--text-muted)] group-hover:text-[var(--text)] transition-colors shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <span class="truncate font-normal text-left hidden sm:inline">Search panel, domains...</span>
            <span class="truncate font-normal text-left sm:hidden text-[11px]">Search...</span>
        </div>
        <kbd class="font-mono text-[10px] bg-[var(--bg-shell)] border border-[var(--border)] rounded-md px-1.5 py-0.5 text-[var(--text-muted)] font-semibold shadow-2xs shrink-0 hidden md:inline-block">Ctrl K</kbd>
    </button>

    <!-- Search Palette Modal -->
    <div
        x-show="$wire.isOpen"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 overflow-y-auto p-2 sm:p-6 md:p-20"
        role="dialog"
        aria-modal="true"
        style="display: none;"
    >
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="close"></div>

        <!-- Palette Panel -->
        <div
            x-show="$wire.isOpen"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="mx-auto max-w-xl w-full transform divide-y divide-[var(--border)] overflow-hidden rounded-[20px] sm:rounded-[24px] bg-[var(--bg-card)] border border-[var(--border)] shadow-2xl transition-all"
            @keydown.escape.window="$wire.close()"
        >
            <div class="relative flex items-center px-4 py-3.5">
                <svg class="pointer-events-none w-5 h-5 text-[var(--text-muted)] shrink-0 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    x-ref="searchInput"
                    type="text"
                    wire:model.live.debounce.150ms="query"
                    class="h-10 w-full border-0 bg-transparent pl-3 pr-4 text-[var(--text)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-0 text-sm"
                    placeholder="Search websites, accounts, servers, or navigation..."
                    autocomplete="off"
                />
                <button
                    wire:click="close"
                    class="text-[11px] font-semibold text-[var(--text-muted)] hover:text-[var(--text)] px-2 py-1 rounded-full border border-[var(--border)] bg-[var(--bg-shell)] transition"
                >
                    ESC
                </button>
            </div>

            <!-- Results container -->
            @if (count($results) > 0)
                <ul class="max-h-96 scroll-py-2 overflow-y-auto p-2 text-sm text-[var(--text)] divide-y divide-[var(--border)]/40">
                    @foreach ($results as $item)
                        <li>
                            <a
                                href="{{ $item['url'] }}"
                                class="flex items-center gap-3 rounded-[16px] px-3.5 py-2.5 hover:bg-[var(--bg-shell)] transition-colors group"
                            >
                                <span class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0 group-hover:bg-blue-600 group-hover:text-white transition-colors">
                                    @if ($item['icon'] === 'globe')
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                                    @elseif ($item['icon'] === 'user' || $item['icon'] === 'users')
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    @elseif ($item['icon'] === 'server')
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                                    @else
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    @endif
                                </span>

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <span class="font-medium text-[var(--text)] truncate">{{ $item['title'] }}</span>
                                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text-muted)]">{{ $item['type'] }}</span>
                                    </div>
                                    <p class="text-xs text-[var(--text-muted)] truncate mt-0.5">{{ $item['subtitle'] }}</p>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @elseif (strlen(trim($query)) >= 2)
                <div class="p-8 text-center text-sm text-[var(--text-muted)]">
                    <p>No results found for "<span class="font-semibold text-[var(--text)]">{{ $query }}</span>"</p>
                </div>
            @else
                <div class="p-4 text-xs text-[var(--text-muted)] text-center">
                    Type at least 2 characters to search across domains, accounts, and navigation.
                </div>
            @endif
        </div>
    </div>
</div>
