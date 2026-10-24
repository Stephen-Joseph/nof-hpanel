@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'loading' => null,
    'href' => null,
])

@php
$baseClasses = 'inline-flex items-center justify-center font-medium rounded-full transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-blue-500 dark:focus-visible:ring-offset-slate-900 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer select-none active:scale-[0.98]';

$sizeClasses = match($size) {
    'xs' => 'h-7 px-3 text-xs gap-1.5',
    'sm' => 'h-8 px-4 text-xs gap-1.5',
    'lg' => 'h-12 px-6 text-base gap-2.5',
    default => 'h-10 px-5 text-sm gap-2',
};

$variantClasses = match($variant) {
    'primary' => 'bg-gradient-primary text-white shadow-xs hover:opacity-95 border border-transparent',
    'secondary' => 'bg-[var(--bg-shell)] hover:bg-[var(--bg-card)] text-[var(--text)] border border-[var(--border)] shadow-2xs',
    'danger' => 'bg-rose-600 hover:bg-rose-500 text-white shadow-xs border border-transparent',
    'success' => 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-xs border border-transparent',
    'outline' => 'bg-[var(--bg-card)] hover:bg-[var(--bg-shell)] text-[var(--text)] border border-[var(--border)] shadow-2xs',
    'ghost' => 'bg-transparent text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-shell)] border border-transparent',
    default => 'bg-gradient-primary text-white shadow-xs hover:opacity-95 border border-transparent',
};
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        wire:navigate
        {{ $attributes->merge(['class' => "{$baseClasses} {$sizeClasses} {$variantClasses}"]) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->merge(['class' => "$baseClasses $sizeClasses $variantClasses"]) }}
        @if ($loading) wire:loading.attr="disabled" wire:target="{{ $loading }}" @endif
    >
        @if ($loading)
            <svg wire:loading wire:target="{{ $loading }}" class="animate-spin -ml-0.5 mr-1.5 h-4 w-4 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        @endif
        {{ $slot }}
    </button>
@endif
