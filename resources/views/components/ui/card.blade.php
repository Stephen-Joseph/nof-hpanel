@props([
    'title' => null,
    'subtitle' => null,
    'headerActions' => null,
    'footer' => null,
    'padding' => true,
    'variant' => 'default', // default | highlighted | dark | shell
])

@php
$variantClasses = match($variant) {
    'highlighted' => 'bg-gradient-primary text-white shadow-sm border border-transparent',
    'dark' => 'bg-card-dark-gradient text-white border border-[var(--border)] shadow-sm',
    'shell' => 'bg-[var(--bg-shell)] border border-[var(--border)] shadow-2xs',
    default => 'bg-[var(--bg-card)] border border-[var(--border)] shadow-xs dark:shadow-none',
};

$headerBorderClasses = match($variant) {
    'highlighted' => 'border-white/15',
    'dark' => 'border-white/10',
    default => 'border-[var(--border)]',
};

$footerBorderClasses = match($variant) {
    'highlighted' => 'border-white/15 bg-white/5',
    'dark' => 'border-white/10 bg-white/5',
    default => 'border-[var(--border)] bg-[var(--bg-shell)]/60',
};

$titleClasses = match($variant) {
    'highlighted' => 'text-white font-bold',
    'dark' => 'text-white font-bold',
    default => 'text-[var(--text)] font-semibold',
};

$subtitleClasses = match($variant) {
    'highlighted' => 'text-white/80',
    'dark' => 'text-slate-400',
    default => 'text-[var(--text-muted)]',
};
@endphp

<div {{ $attributes->merge(['class' => "{$variantClasses} rounded-[20px] overflow-hidden transition-colors duration-150 relative"]) }}>
    @if ($title || $headerActions || isset($header))
        <div class="px-6 py-4.5 border-b {{ $headerBorderClasses }} flex items-center justify-between gap-4">
            @if (isset($header))
                {{ $header }}
            @else
                <div>
                    @if ($title)
                        <h3 class="text-base {{ $titleClasses }} leading-6 tracking-tight">{{ $title }}</h3>
                    @endif
                    @if ($subtitle)
                        <p class="mt-0.5 text-xs {{ $subtitleClasses }}">{{ $subtitle }}</p>
                    @endif
                </div>
            @endif

            @if ($headerActions || isset($actions))
                <div class="flex items-center gap-2">
                    {{ $headerActions ?? $actions }}
                </div>
            @endif
        </div>
    @endif

    <div @class(['p-5 sm:p-6' => $padding])>
        {{ $slot }}
    </div>

    @if ($footer || isset($footerSlot))
        <div class="px-6 py-3.5 border-t {{ $footerBorderClasses }} flex items-center justify-between">
            {{ $footer ?? $footerSlot }}
        </div>
    @endif
</div>

