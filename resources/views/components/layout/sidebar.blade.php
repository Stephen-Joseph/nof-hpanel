@php
    /** @var \App\Models\User|null $user */
    $user = Auth::user();
@endphp

<div class="flex flex-col h-full justify-between p-4 sm:p-5 select-none">
    <!-- Top Section: Brand & Nav Links -->
    <div class="space-y-6">
        <!-- Logo Banner -->
        <div class="px-2 pt-1 flex items-center justify-between">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center group py-1" @click="sidebarOpen = false">
                <img 
                    src="{{ asset('H-Paneltm(1).png') }}" 
                    onerror="this.onerror=null;this.src='{{ asset('logo.png') }}';"
                    alt="{{ config('app.name', 'HPanel') }}" 
                    style="max-height: 40px; width: auto; max-width: 190px;"
                    class="h-9 sm:h-10 w-auto max-w-[190px] object-contain group-hover:opacity-95 transition-all duration-150 filter drop-shadow-2xs dark:drop-shadow-[0_2px_8px_rgba(56,189,248,0.2)]" 
                />
            </a>

            <!-- Mobile Drawer Close Button -->
            <button
                type="button"
                @click="sidebarOpen = false"
                class="lg:hidden w-8 h-8 rounded-full border border-[var(--border)] flex items-center justify-center text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-card)] transition cursor-pointer"
                aria-label="Close sidebar"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Section 1: MENU -->
        <nav class="space-y-1">
            <p class="px-3 text-[11px] font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">
                Menu
            </p>

            <!-- Dashboard -->
            <x-layout.nav-item :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                <x-slot:label>Dashboard</x-slot:label>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
            </x-layout.nav-item>

            @if ($user?->isReseller())
                <!-- Reseller Center -->
                <x-layout.nav-item :href="route('reseller.index')" :active="request()->routeIs('reseller*')">
                    <x-slot:label>Reseller Center</x-slot:label>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </x-layout.nav-item>
            @endif

            <!-- Websites & Domains -->
            <x-layout.nav-item :href="route('domains.index')" :active="request()->routeIs('domains*')">
                <x-slot:label>Websites &amp; Domains</x-slot:label>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                </svg>
            </x-layout.nav-item>

            <!-- Accounts (All Roles: Admin sees all, Reseller sees own subtree, Customer sees own) -->
            <x-layout.nav-item :href="route('accounts.index')" :active="request()->routeIs('accounts*')">
                <x-slot:label>Accounts</x-slot:label>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
            </x-layout.nav-item>

            @if ($user?->isAdmin() || $user?->isReseller())
                <!-- Hosting Packages -->
                <x-layout.nav-item :href="route('packages.index')" :active="request()->routeIs('packages*')">
                    <x-slot:label>Hosting Packages</x-slot:label>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </x-layout.nav-item>
            @endif

            @if ($user?->isAdmin())
                <!-- Server Nodes -->
                <x-layout.nav-item :href="route('servers.index')" :active="request()->routeIs('servers*')">
                    <x-slot:label>Server Nodes</x-slot:label>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                    </svg>
                </x-layout.nav-item>

                <!-- Web Server Config -->
                <x-layout.nav-item :href="route('admin.web-server.index')" :active="request()->routeIs('admin.web-server*')">
                    <x-slot:label>Web Server</x-slot:label>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </x-layout.nav-item>

                <!-- Jobs -->
                <x-layout.nav-item :href="route('admin.jobs.index')" :active="request()->routeIs('admin.jobs*')">
                    <x-slot:label>Jobs</x-slot:label>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                </x-layout.nav-item>
            @endif

            @if ($user?->isAdmin() || $user?->isReseller())
                <!-- Usage -->
                <x-layout.nav-item :href="route('admin.usage.index')" :active="request()->routeIs('admin.usage*')">
                    <x-slot:label>Usage</x-slot:label>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </x-layout.nav-item>
            @endif

            @if ($user?->isAdmin())
                <!-- API Tokens -->
                <x-layout.nav-item :href="route('admin.api-tokens.index')" :active="request()->routeIs('admin.api-tokens*')">
                    <x-slot:label>API Tokens</x-slot:label>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </x-layout.nav-item>

                <!-- Audit Logs -->
                <x-layout.nav-item :href="route('admin.audit-logs')" :active="request()->routeIs('admin.audit-logs*')">
                    <x-slot:label>Audit Trail</x-slot:label>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </x-layout.nav-item>
            @endif

            <!-- API Integration -->
            <div x-data="{ open: @js(request()->routeIs('api.integration.*')) }">
                <button
                    type="button"
                    @click="open = !open"
                    class="w-full relative flex items-center justify-between px-3.5 py-2.5 rounded-full text-sm font-medium transition-colors duration-150 select-none cursor-pointer {{ request()->routeIs('api.integration.*') ? 'bg-blue-50 dark:bg-blue-500/10 text-primary-600 dark:text-primary-400 font-semibold before:absolute before:left-0 before:top-2 before:bottom-2 before:w-1 before:bg-blue-600 before:rounded-r-md' : 'text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-card)]/80' }}"
                >
                    <span class="flex items-center gap-3">
                        <span class="shrink-0 {{ request()->routeIs('api.integration.*') ? 'text-blue-600 dark:text-blue-400' : 'text-[var(--text-muted)]' }}">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                            </svg>
                        </span>
                        <span class="truncate">API Integration</span>
                    </span>
                    <svg 
                        class="w-3.5 h-3.5 transition-transform duration-150 text-[var(--text-muted)]" 
                        :class="open ? 'rotate-180' : ''" 
                        fill="none" 
                        viewBox="0 0 24 24" 
                        stroke="currentColor" 
                        stroke-width="2"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <div x-show="open" x-cloak class="pl-8 pr-2 pt-1 pb-1 space-y-1">
                    <a
                        href="{{ route('api.integration.overview') }}"
                        wire:navigate
                        class="block px-3 py-1.5 rounded-xl text-xs font-medium transition-colors {{ request()->routeIs('api.integration.overview') ? 'text-blue-600 dark:text-blue-400 font-semibold bg-[var(--bg-shell)]' : 'text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-card)]/50' }}"
                    >
                        Overview
                    </a>
                    <a
                        href="{{ route('api.integration.tokens') }}"
                        wire:navigate
                        class="block px-3 py-1.5 rounded-xl text-xs font-medium transition-colors {{ request()->routeIs('api.integration.tokens*') ? 'text-blue-600 dark:text-blue-400 font-semibold bg-[var(--bg-shell)]' : 'text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-card)]/50' }}"
                    >
                        Tokens
                    </a>
                    <a
                        href="{{ route('api.integration.webhooks') }}"
                        wire:navigate
                        class="block px-3 py-1.5 rounded-xl text-xs font-medium transition-colors {{ request()->routeIs('api.integration.webhooks*') ? 'text-blue-600 dark:text-blue-400 font-semibold bg-[var(--bg-shell)]' : 'text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-card)]/50' }}"
                    >
                        Webhooks
                    </a>
                    <a
                        href="{{ route('api.integration.logs') }}"
                        wire:navigate
                        class="block px-3 py-1.5 rounded-xl text-xs font-medium transition-colors {{ request()->routeIs('api.integration.logs*') ? 'text-blue-600 dark:text-blue-400 font-semibold bg-[var(--bg-shell)]' : 'text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-card)]/50' }}"
                    >
                        Logs
                    </a>
                    <a
                        href="{{ route('api.integration.docs') }}"
                        wire:navigate
                        class="block px-3 py-1.5 rounded-xl text-xs font-medium transition-colors {{ request()->routeIs('api.integration.docs*') ? 'text-blue-600 dark:text-blue-400 font-semibold bg-[var(--bg-shell)]' : 'text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-card)]/50' }}"
                    >
                        Docs
                    </a>
                </div>
            </div>
        </nav>

        <!-- Section 2: GENERAL -->
        <nav class="space-y-1 pt-2 border-t border-[var(--border)]">
            <p class="px-3 text-[11px] font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">
                General
            </p>

            <!-- Settings -->
            <x-layout.nav-item :href="route('profile.security')" :active="request()->routeIs('profile.security*')">
                <x-slot:label>Settings &amp; 2FA</x-slot:label>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </x-layout.nav-item>

            <!-- Help / Docs -->
            <x-layout.nav-item :href="route('api.integration.docs')" :active="request()->routeIs('api.integration.docs*')">
                <x-slot:label>Help &amp; Docs</x-slot:label>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </x-layout.nav-item>

            <!-- Logout -->
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <button
                    type="submit"
                    class="w-full flex items-center gap-3 px-3.5 py-2.5 rounded-full text-sm font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-500/10 transition-colors duration-150 cursor-pointer"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    <span>Sign Out</span>
                </button>
            </form>
        </nav>
    </div>

    <!-- Bottom Promo Card -->
    <div class="pt-6">
        <x-layout.promo-card
            title="Download our Mobile App"
            description="Manage servers &amp; sites on the go"
            button-text="Download"
        />
    </div>
</div>

