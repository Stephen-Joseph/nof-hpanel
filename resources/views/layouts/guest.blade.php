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
            <a href="/" class="inline-flex items-center justify-center group mb-2">
                <img 
                    src="{{ asset('H-Paneltm(1).png') }}" 
                    alt="{{ config('app.name', 'HPanel') }}" 
                    class="h-14 sm:h-16 w-auto max-w-[260px] object-contain mx-auto group-hover:opacity-95 transition-opacity duration-150" 
                />
            </a>
            <p class="text-xs text-[var(--text-muted)]">cPanel-Style Cloud Hosting Management</p>
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
