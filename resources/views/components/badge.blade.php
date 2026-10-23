@props([
    'variant' => 'neutral',
    'dot' => false,
    'size' => 'md',
])

@php
$baseClasses = 'inline-flex items-center font-medium rounded-full border transition-colors';

$sizeClasses = match($size) {
    'sm' => 'px-2 py-0.5 text-xs gap-1',
    'lg' => 'px-3 py-1 text-sm gap-2',
    default => 'px-2.5 py-0.5 text-xs gap-1.5',
};

$variantClasses = match($variant) {
    'success' => 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-500/20',
    'danger' => 'bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-400 border-rose-200 dark:border-rose-500/20',
    'warning' => 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-500/20',
    'info' => 'bg-sky-50 dark:bg-sky-500/10 text-sky-700 dark:text-sky-400 border-sky-200 dark:border-sky-500/20',
    'primary' => 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 border-indigo-200 dark:border-indigo-500/20',
    default => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700',
};

$dotClasses = match($variant) {
    'success' => 'bg-emerald-500',
    'danger' => 'bg-rose-500',
    'warning' => 'bg-amber-500',
    'info' => 'bg-sky-500',
    'primary' => 'bg-indigo-500',
    default => 'bg-slate-400',
};
@endphp

<span {{ $attributes->merge(['class' => "$baseClasses $sizeClasses $variantClasses"]) }}>
    @if ($dot)
        <span class="w-1.5 h-1.5 rounded-full {{ $dotClasses }}"></span>
    @endif
    {{ $slot }}
</span>

