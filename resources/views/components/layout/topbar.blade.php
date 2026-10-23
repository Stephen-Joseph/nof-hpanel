@php
    /** @var \App\Models\User|null $user */
    $user = Auth::user();
@endphp

<header class="flex items-center justify-between gap-4 px-4 sm:px-6 lg:px-8 py-3.5 border-b border-[var(--border)] bg-[var(--bg-shell)] transition-colors duration-150">
    <!-- Left Tools: Mobile Hamburger & Search -->
    <div class="flex items-center gap-3 sm:gap-4 flex-1 max-w-lg">
        <!-- Mobile hamburger toggle -->
        <button
            type="button"
            @click="sidebarOpen = true"
            class="lg:hidden w-9 h-9 rounded-full flex items-center justify-center border border-[var(--border)] bg-[var(--bg-card)] text-[var(--text-muted)] hover:text-[var(--text)] transition"
            aria-label="Open sidebar"
        >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>

        <!-- Global Search Palette Trigger -->
        <livewire:navigation.global-search />
    </div>

    <!-- Right Tools: Icons, Theme Toggle, User Block -->
    <div class="flex items-center gap-2 sm:gap-3">
        <!-- Messages Icon Button -->
        <x-ui.icon-button label="Messages" size="md">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
        </x-ui.icon-button>

        <!-- Notifications Icon Button with Unread Dot -->
        <div class="relative">
            <x-ui.icon-button label="Notifications" size="md">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
            </x-ui.icon-button>
            <span class="absolute top-1 right-1 w-2 h-2 rounded-full bg-rose-500 ring-2 ring-[var(--bg-shell)]"></span>
        </div>

        <!-- Light / Dark Theme Toggle -->
        <x-ui.theme-toggle size="md" />

        <div class="h-6 w-px bg-[var(--border)] hidden sm:block mx-1"></div>

        <!-- User Profile Dropdown -->
        <x-ui.dropdown align="right" width="56">
            <x-slot:trigger>
                <button
                    type="button"
                    class="flex items-center gap-2.5 p-1 sm:px-2 sm:py-1 rounded-full hover:bg-[var(--bg-card)] border border-transparent hover:border-[var(--border)] transition duration-150 cursor-pointer"
                >
                    <div class="w-9 h-9 rounded-full bg-gradient-primary text-white font-bold text-xs flex items-center justify-center shrink-0 shadow-2xs">
                        {{ strtoupper(substr($user?->name ?? 'U', 0, 1)) }}
                    </div>
                    <div class="hidden sm:block text-left pr-1">
                        <p class="text-xs font-bold text-[var(--text)] leading-tight truncate max-w-[120px]">
                            {{ $user?->name ?? 'User' }}
                        </p>
                        <p class="text-[10px] text-[var(--text-muted)] truncate max-w-[120px]">
                            {{ $user?->email ?? $user?->role?->label() }}
                        </p>
                    </div>
                    <svg class="w-3.5 h-3.5 text-[var(--text-muted)] hidden sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
            </x-slot:trigger>

            <x-slot:content>
                <div class="px-4 py-3 border-b border-[var(--border)]">
                    <p class="text-xs font-bold text-[var(--text)] truncate">{{ $user?->name }}</p>
                    <p class="text-[11px] text-[var(--text-muted)] truncate">{{ $user?->email }}</p>
                    <div class="mt-2">
                        <x-ui.chip :variant="$user?->isAdmin() ? 'primary' : ($user?->isReseller() ? 'success' : 'neutral')" size="sm">
                            {{ $user?->role?->label() ?? 'Customer' }}
                        </x-ui.chip>
                    </div>
                </div>

                <div class="py-1 text-xs">
                    <a href="{{ route('profile.security') }}" wire:navigate class="flex items-center gap-2 px-3.5 py-2 text-[var(--text)] hover:bg-[var(--bg-shell)] rounded-xl transition">
                        <svg class="w-4 h-4 text-[var(--text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        Security &amp; 2FA
                    </a>

                    @if ($user?->isAdmin())
                        <a href="{{ route('admin.audit-logs') }}" wire:navigate class="flex items-center gap-2 px-3.5 py-2 text-[var(--text)] hover:bg-[var(--bg-shell)] rounded-xl transition">
                            <svg class="w-4 h-4 text-[var(--text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                            Audit Trail
                        </a>
                    @endif

                    <div class="my-1 border-t border-[var(--border)]"></div>

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <button type="submit" class="w-full flex items-center gap-2 px-3.5 py-2 text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-500/10 rounded-xl transition text-left cursor-pointer">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            Sign Out
                        </button>
                    </form>
                </div>
            </x-slot:content>
        </x-ui.dropdown>
    </div>
</header>

