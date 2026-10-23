@props([
    'variant' => 'default',
    'size' => 'md',
    'type' => 'button',
    'loading' => null,
    'label' => null,
])

@php
$baseClasses = 'inline-flex items-center justify-center rounded-full transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-[var(--primary-500)] dark:focus-visible:ring-offset-[var(--bg-app)] disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer shrink-0 active:scale-95';

$sizeClasses = match($size) {
    'xs' => 'w-7 h-7 text-xs',
    'sm' => 'w-8 h-8 text-xs',
    'lg' => 'w-11 h-11 text-base',
    default => 'w-9 h-9 text-sm',
};

$variantClasses = match($variant) {
    'primary' => 'bg-gradient-primary text-white shadow-xs hover:opacity-95 border border-transparent',
    'ghost' => 'bg-transparent text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-shell)] border border-transparent',
    'danger' => 'bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-100 dark:hover:bg-rose-500/20 border border-transparent',
    'dark' => 'bg-white/10 hover:bg-white/20 text-white border border-transparent',
    default => 'bg-[var(--bg-card)] hover:bg-[var(--bg-shell)] text-[var(--text-muted)] hover:text-[var(--text)] border border-[var(--border)] shadow-2xs',
};
@endphp

<button
    type="{{ $type }}"
    @if ($label) aria-label="{{ $label }}" title="{{ $label }}" @endif
    {{ $attributes->merge(['class' => "{$baseClasses} {$sizeClasses} {$variantClasses}"]) }}
    @if ($loading) wire:loading.attr="disabled" wire:target="{{ $loading }}" @endif
>
    @if ($loading)
        <svg wire:loading wire:target="{{ $loading }}" class="animate-spin h-4 w-4 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
    @endif
    <span @if ($loading) wire:loading.remove wire:target="{{ $loading }}" @endif class="inline-flex items-center justify-center">
        {{ $slot }}
    </span>
</button>

