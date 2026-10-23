@props([
    'title' => 'Resource & Traffic Analytics',
    'subtitle' => null,
    'bars' => null,
])

@php
$defaultBars = [
    ['day' => 'S', 'height' => 48, 'state' => 'inactive'],
    ['day' => 'M', 'height' => 78, 'state' => 'solid-medium'],
    ['day' => 'T', 'height' => 64, 'state' => 'solid-light', 'tooltip' => '74%'],
    ['day' => 'W', 'height' => 92, 'state' => 'solid-dark'],
    ['day' => 'T', 'height' => 68, 'state' => 'inactive'],
    ['day' => 'F', 'height' => 52, 'state' => 'inactive'],
    ['day' => 'S', 'height' => 58, 'state' => 'inactive'],
];

$items = $bars ?? $defaultBars;
@endphp

<x-ui.card :title="$title" :subtitle="$subtitle">
    <div class="pt-2 pb-1">
        <!-- Bars Container -->
        <div class="h-44 flex items-end justify-between gap-2 sm:gap-3 px-2">
            @foreach ($items as $bar)
                @php
                    $h = $bar['height'] ?? 50;
                    $state = $bar['state'] ?? 'inactive';
                    $hasTooltip = isset($bar['tooltip']);

                    $bgClass = match($state) {
                        'solid-light' => 'bg-blue-400 dark:bg-blue-400',
                        'solid-medium' => 'bg-blue-600 dark:bg-blue-500',
                        'solid-dark' => 'bg-blue-900 dark:bg-blue-700',
                        default => 'bg-hatch border border-[var(--border)]',
                    };
                @endphp

                <div class="flex-1 flex flex-col items-center h-full justify-end relative group">
                    @if ($hasTooltip)
                        <!-- Active Pill Tooltip -->
                        <div class="absolute -top-3 left-1/2 -translate-x-1/2 z-10">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)] shadow-xs">
                                {{ $bar['tooltip'] }}
                            </span>
                        </div>
                    @endif

                    <!-- Capsule Bar -->
                    <div
                        class="w-full max-w-[36px] rounded-full transition-all duration-300 hover:opacity-90 {{ $bgClass }}"
                        style="height: {{ $h }}%;"
                    ></div>
                </div>
            @endforeach
        </div>

        <!-- Day Letters Row -->
        <div class="flex items-center justify-between gap-2 sm:gap-3 px-2 mt-3 pt-2 border-t border-[var(--border)]">
            @foreach ($items as $bar)
                <div class="flex-1 text-center">
                    <span class="text-xs font-semibold text-[var(--text-muted)]">
                        {{ $bar['day'] }}
                    </span>
                </div>
            @endforeach
        </div>
    </div>
</x-ui.card>

