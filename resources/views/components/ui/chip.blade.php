@props([
    'variant' => 'neutral',
    'dot' => false,
    'size' => 'md',
])

@php
$baseClasses = 'inline-flex items-center font-semibold rounded-full transition-colors select-none';

$sizeClasses = match($size) {
    'sm' => 'px-2.5 py-0.5 text-[11px] gap-1',
    'lg' => 'px-3.5 py-1 text-sm gap-2',
    default => 'px-3 py-1 text-xs gap-1.5',
};

$variantClasses = match($variant) {
    'success' => 'bg-[var(--status-success-bg)] text-[var(--status-success-text)]',
    'info' => 'bg-[var(--status-info-bg)] text-[var(--status-info-text)]',
    'warning' => 'bg-[var(--status-warning-bg)] text-[var(--status-warning-text)]',
    'danger' => 'bg-[var(--status-danger-bg)] text-[var(--status-danger-text)]',
    'primary' => 'bg-[var(--primary-100)] text-[var(--primary-700)] dark:text-[var(--primary-300)]',
    default => 'bg-[var(--bg-shell)] text-[var(--text-muted)] border border-[var(--border)]',
};

$dotClasses = match($variant) {
    'success' => 'bg-[var(--status-success-text)]',
    'info' => 'bg-[var(--status-info-text)]',
    'warning' => 'bg-[var(--status-warning-text)]',
    'danger' => 'bg-[var(--status-danger-text)]',
    'primary' => 'bg-[var(--primary-500)]',
    default => 'bg-[var(--text-muted)]',
};
@endphp

<span {{ $attributes->merge(['class' => "{$baseClasses} {$sizeClasses} {$variantClasses}"]) }}>
    @if ($dot)
        <span class="w-1.5 h-1.5 rounded-full {{ $dotClasses }} shrink-0"></span>
    @endif
    {{ $slot }}
</span>

