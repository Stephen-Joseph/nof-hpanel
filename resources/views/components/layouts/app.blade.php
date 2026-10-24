<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'HPanel') }}</title>

    <!-- Theme Preference Initialization (Prevents FOUC) -->
    <script>
        (function() {
            var theme = localStorage.getItem('theme') || localStorage.getItem('color-theme');
            if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>

    <!-- Scripts & Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen font-sans antialiased bg-[var(--bg-shell)] text-[var(--text)] transition-colors duration-200" x-data="{ sidebarOpen: false }">

    <!-- Mobile Off-Canvas Sidebar Drawer -->
    <div
        x-show="sidebarOpen"
        class="relative z-50 lg:hidden"
        role="dialog"
        aria-modal="true"
        style="display: none;"
    >
        <!-- Backdrop -->
        <div
            x-show="sidebarOpen"
            x-transition:enter="transition-opacity ease-linear duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-linear duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs"
            @click="sidebarOpen = false"
        ></div>

        <div class="fixed inset-0 flex">
            <div
                x-show="sidebarOpen"
                x-transition:enter="transition ease-in-out duration-300 transform"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in-out duration-300 transform"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                class="relative mr-16 flex w-full max-w-xs flex-1"
            >
                <div class="flex flex-col flex-1 bg-[var(--bg-shell)] border-r border-[var(--border)] overflow-y-auto">
                    <div class="flex items-center justify-between px-6 pt-5 pb-2">
                        <span class="text-sm font-bold text-[var(--text)]">Navigation</span>
                        <button type="button" @click="sidebarOpen = false" class="w-8 h-8 rounded-full border border-[var(--border)] flex items-center justify-center text-[var(--text-muted)] hover:text-[var(--text)]">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="flex-1">
                        <x-layout.sidebar />
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main App Layout (Full-bleed, Natural Dashboard Layout) -->
    <div class="min-h-screen flex w-full bg-[var(--bg-shell)]">

        <!-- Desktop Fixed Sidebar -->
        <aside class="hidden lg:flex w-64 flex-col border-r border-[var(--border)] bg-[var(--bg-shell)] shrink-0 h-screen sticky top-0 overflow-y-auto">
            <x-layout.sidebar />
        </aside>

        <!-- Main Content Area -->
        <div class="flex-1 flex flex-col min-w-0 min-h-screen bg-[var(--bg-shell)]">
            <!-- Topbar Navigation -->
            <x-layout.topbar />

            <!-- Main Page Slot -->
            <main class="flex-1 p-4 sm:p-6 lg:p-8 w-full max-w-7xl mx-auto">
                {{ $slot }}
            </main>

            <!-- Subtle Shell Footer -->
            <footer class="px-6 py-4 border-t border-[var(--border)] text-center text-xs text-[var(--text-muted)]">
                &copy; {{ date('Y') }} {{ config('app.name', 'HPanel') }} &middot; Isolated Web Hosting &amp; Server Infrastructure
            </footer>
        </div>
    </div>

    <!-- Reusable System Toast Notification Banner -->
    <x-ui.toast />

    @livewireScripts
</body>
</html>

