@props([
    'size' => 'md',
])

@php
    $sizeClasses = match ($size) {
        'sm' => 'w-8 h-8',
        'lg' => 'w-11 h-11',
        default => 'w-9 h-9',
    };
@endphp

<button
    type="button"
    x-data="{
        dark: false,
        init() {
            this.dark = document.documentElement.classList.contains('dark');
        },
        toggle() {
            this.dark = !this.dark;
            if (this.dark) {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
                localStorage.setItem('color-theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
                localStorage.setItem('color-theme', 'light');
            }
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark: this.dark } }));
        }
    }"
    @click="toggle()"
    {{ $attributes->merge([
        'class' => "{$sizeClasses} rounded-full flex items-center justify-center bg-card text-muted hover:text-text border border-border transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 active:scale-95 cursor-pointer shadow-xs",
        'aria-label' => 'Toggle light/dark theme',
    ]) }}
    :title="dark ? 'Switch to light mode' : 'Switch to dark mode'"
>
    <!-- Sun icon (shown in dark mode) -->
    <svg x-show="dark" class="w-4 h-4 text-amber-400" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" style="display: none;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
    </svg>
    <!-- Moon icon (shown in light mode) -->
    <svg x-show="!dark" class="w-4 h-4 text-slate-600 dark:text-slate-300" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
    </svg>
</button>
