@props([
    'title' => '',
    'value' => '0',
    'unit' => null,
    'trendValue' => null,
    'trendLabel' => 'Increased from last month',
    'highlighted' => false,
    'href' => null,
])

@php
$cardBg = $highlighted
    ? 'bg-gradient-primary text-white shadow-sm border border-transparent'
    : 'bg-[var(--bg-card)] border border-[var(--border)] text-[var(--text)] shadow-xs dark:shadow-none';

$arrowBtnClasses = $highlighted
    ? 'bg-white/20 hover:bg-white/30 text-white'
    : 'border border-[var(--border)] text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-shell)]';

$titleClasses = $highlighted ? 'text-white/90' : 'text-[var(--text)]';
$trendLabelClasses = $highlighted ? 'text-white/85' : 'text-[var(--text-muted)]';

$badgeClasses = $highlighted
    ? 'bg-white/20 text-white'
    : 'bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)]';
@endphp

<div {{ $attributes->merge(['class' => "rounded-[20px] p-5 sm:p-6 {$cardBg} flex flex-col justify-between h-48 transition-all duration-150 relative select-none"]) }}>
    <!-- Header: Title and Circular Arrow Button -->
    <div class="flex items-center justify-between gap-2">
        <span class="text-sm font-semibold {{ $titleClasses }} truncate">
            {{ $title }}
        </span>

        @if ($href)
            <a
                href="{{ $href }}"
                wire:navigate
                class="w-8 h-8 rounded-full flex items-center justify-center transition-transform hover:scale-105 active:scale-95 shrink-0 {{ $arrowBtnClasses }}"
                aria-label="View {{ $title }}"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M17 7H7M17 7V17" />
                </svg>
            </a>
        @else
            <button
                type="button"
                class="w-8 h-8 rounded-full flex items-center justify-center transition-transform shrink-0 cursor-default {{ $arrowBtnClasses }}"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M17 7H7M17 7V17" />
                </svg>
            </button>
        @endif
    </div>

    <!-- Main Metric Readout -->
    <div>
        <div class="text-4xl font-extrabold tracking-tight flex items-baseline gap-1.5">
            <span>{{ $value }}</span>
            @if ($unit)
                <span class="text-base font-semibold {{ $highlighted ? 'text-white/80' : 'text-[var(--text-muted)]' }}">{{ $unit }}</span>
            @endif
        </div>

        @if ($trendValue || $trendLabel)
            <div class="mt-2.5 flex items-center gap-1.5 text-xs {{ $trendLabelClasses }}">
                @if ($trendValue)
                    <span class="inline-flex items-center justify-center px-2 py-0.5 rounded-full font-bold text-[11px] {{ $badgeClasses }}">
                        {{ $trendValue }}
                    </span>
                @endif
                <span class="truncate">{{ $trendLabel }}</span>
            </div>
        @endif
    </div>
</div>

