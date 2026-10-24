<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'HPanel') }}</title>

    <!-- Theme Initialization (Zero FOUC) -->
    <script>
        (function() {
            try {
                const storedTheme = localStorage.getItem('theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (storedTheme === 'dark' || (!storedTheme && prefersDark)) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            } catch (e) {}
        })();
    </script>

    <!-- Scripts & Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans text-[var(--text)] antialiased bg-[var(--bg-app)] flex flex-col justify-center py-12 sm:px-6 lg:px-8 transition-colors duration-200">
    <!-- Top-right theme toggle -->
    <div class="fixed top-4 right-4 z-50">
        <x-ui.theme-toggle />
    </div>

    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <div class="text-center">
            <a href="/" class="inline-flex items-center gap-2.5">
                <div class="w-10 h-10 rounded-full bg-gradient-primary flex items-center justify-center text-white shadow-xs">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4" />
                    </svg>
                </div>
                <span class="text-2xl font-bold tracking-tight text-[var(--text)]">
                    {{ config('app.name', 'HPanel') }}
                </span>
            </a>
            <p class="mt-2 text-xs text-[var(--text-muted)]">cPanel-Style Cloud Hosting Management</p>
        </div>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md px-4 sm:px-0">
        <div class="bg-[var(--bg-card)] border border-[var(--border)] py-8 px-6 shadow-xs rounded-[28px] sm:px-10">
            {{ $slot }}
        </div>
    </div>

    @livewireScripts
</body>
</html>
