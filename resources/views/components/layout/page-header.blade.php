@props([
    'title' => '',
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6']) }}>
    <div>
        <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold tracking-tight text-[var(--text)]">
            {!! $title !!}
        </h1>
        @if ($subtitle)
            <p class="text-xs sm:text-sm text-[var(--text-muted)] mt-1">
                {{ $subtitle }}
            </p>
        @endif
    </div>

    @if (isset($actions) || $slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2 sm:gap-3 self-start sm:self-auto shrink-0 mt-1 sm:mt-0">
            {{ $actions ?? $slot }}
        </div>
    @endif
</div>

