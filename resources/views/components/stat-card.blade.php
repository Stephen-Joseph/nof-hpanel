@props([
    'title',
    'value',
    'subtext' => null,
    'progress' => null,
    'variant' => 'indigo',
    'href' => null,
])

@php
$iconBg = match($variant) {
    'emerald' => 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    'amber' => 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
    'rose' => 'bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400',
    'sky' => 'bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400',
    default => 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
};

$progressBar = match($variant) {
    'emerald' => 'bg-emerald-500',
    'amber' => 'bg-amber-500',
    'rose' => 'bg-rose-500',
    'sky' => 'bg-sky-500',
    default => 'bg-indigo-500',
};
@endphp

<div @class([
    'bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-xs transition-all relative overflow-hidden',
    'hover:border-indigo-300 dark:hover:border-slate-700 hover:shadow-md' => $href !== null,
])>
    @if ($href)
        <a href="{{ $href }}" class="absolute inset-0 z-10" aria-label="{{ $title }}"></a>
    @endif

    <div class="flex items-center justify-between">
        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">{{ $title }}</span>
        @if (isset($icon))
            <span class="p-2 rounded-xl {{ $iconBg }} shrink-0">
                {{ $icon }}
            </span>
        @endif
    </div>

    <div class="mt-3 flex items-baseline gap-2">
        <span class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $value }}</span>
        @if (isset($badge))
            {{ $badge }}
        @endif
    </div>

    @if ($progress !== null)
        <div class="mt-3">
            <div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-1.5 overflow-hidden">
                <div class="{{ $progressBar }} h-1.5 rounded-full transition-all duration-500" style="width: {{ min(100, max(0, $progress)) }}%"></div>
            </div>
        </div>
    @endif

    @if ($subtext)
        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400 flex items-center justify-between">
            <span>{{ $subtext }}</span>
            @if ($progress !== null)
                <span class="font-medium text-slate-700 dark:text-slate-300">{{ round($progress) }}%</span>
            @endif
        </p>
    @endif
</div>

