@props([
    'title' => 'No items found',
    'description' => null,
    'icon' => null,
])

<div {{ $attributes->merge(['class' => 'rounded-[20px] border border-dashed border-[var(--border)] p-8 sm:p-12 text-center bg-[var(--bg-shell)]/40 flex flex-col items-center justify-center']) }}>
    <div class="w-14 h-14 rounded-full bg-[var(--bg-card)] border border-[var(--border)] text-blue-600 flex items-center justify-center shadow-2xs mb-4">
        @if ($icon || isset($iconSlot))
            {{ $icon ?? $iconSlot }}
        @else
            <svg class="w-6 h-6 text-[var(--text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
            </svg>
        @endif
    </div>

    <h3 class="text-base font-bold text-[var(--text)] tracking-tight">{{ $title }}</h3>

    @if ($description)
        <p class="mt-1 text-xs text-[var(--text-muted)] max-w-sm">{{ $description }}</p>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-5">
            {{ $slot }}
        </div>
    @endif
</div>

