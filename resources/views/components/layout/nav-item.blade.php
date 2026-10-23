@props([
    'href' => '#',
    'active' => false,
    'badge' => null,
])

@php
$activeClasses = $active
    ? 'bg-blue-50 dark:bg-blue-500/10 text-primary-600 dark:text-primary-400 font-semibold before:absolute before:left-0 before:top-2 before:bottom-2 before:w-1 before:bg-blue-600 before:rounded-r-md'
    : 'text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-card)]/80';
@endphp

<a
    href="{{ $href }}"
    wire:navigate
    @if ($active) aria-current="page" @endif
    {{ $attributes->merge([
        'class' => "relative flex items-center gap-3 px-3.5 py-2.5 rounded-full text-sm font-medium transition-colors duration-150 group select-none {$activeClasses}"
    ]) }}
>
    <span class="shrink-0 {{ $active ? 'text-blue-600 dark:text-blue-400' : 'text-[var(--text-muted)] group-hover:text-[var(--text)]' }} transition-colors">
        {{ $slot }}
    </span>

    <span class="truncate">{{ $label ?? '' }}</span>

    @if ($badge)
        <span class="ml-auto inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold tracking-tight bg-slate-900 text-white dark:bg-blue-600 dark:text-white shadow-2xs">
            {{ $badge }}
        </span>
    @endif
</a>

