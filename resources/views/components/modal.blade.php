@props([
    'name' => null,
    'show' => false,
    'maxWidth' => '2xl',
    'title' => null,
    'footer' => null,
])

@php
$maxWidthClass = match ($maxWidth) {
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '3xl' => 'sm:max-w-3xl',
    '4xl' => 'sm:max-w-4xl',
    default => 'sm:max-w-2xl',
};
@endphp

<div
    x-data="{
        show: @js($show),
        focusables() {
            let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
            return [...$el.querySelectorAll(selector)].filter(el => ! el.hasAttribute('disabled'))
        },
        firstFocusable() { return this.focusables()[0] },
        lastFocusable() { return this.focusables().slice(-1)[0] },
        nextFocusable() { return this.focusables()[this.focusables().indexOf(document.activeElement) + 1] || (this.focusables()[0]) },
        prevFocusable() { return this.focusables()[this.focusables().indexOf(document.activeElement) - 1] || (this.focusables().slice(-1)[0]) },
    }"
    @if ($name)
        x-on:open-modal.window="$event.detail == '{{ $name }}' ? show = true : null"
        x-on:close-modal.window="$event.detail == '{{ $name }}' ? show = false : null"
        x-on:close.stop="show = false"
    @endif
    x-on:keydown.escape.window="show = false"
    x-on:keydown.tab.prevent="$event.shiftKey ? prevFocusable().focus() : nextFocusable().focus()"
    x-show="show"
    role="dialog"
    aria-modal="true"
    class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50 flex items-center justify-center"
    style="display: none;"
>
    <!-- Backdrop -->
    <div
        x-show="show"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 transform transition-all"
        x-on:click="show = false"
    >
        <div class="absolute inset-0 bg-slate-950/70 backdrop-blur-xs"></div>
    </div>

    <!-- Modal Dialog Panel -->
    <div
        x-show="show"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        class="mb-6 bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] overflow-hidden shadow-2xl transform transition-all sm:w-full {{ $maxWidthClass }} z-10"
    >
        @if ($title || isset($header))
            <div class="px-6 py-4.5 border-b border-[var(--border)] flex items-center justify-between">
                @if (isset($header))
                    {{ $header }}
                @else
                    <h3 class="text-base font-bold text-[var(--text)] tracking-tight">{{ $title }}</h3>
                @endif
                <button
                    type="button"
                    x-on:click="show = false"
                    class="w-8 h-8 rounded-full border border-[var(--border)] flex items-center justify-center text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-shell)] transition"
                    aria-label="Close modal"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        @endif

        <div class="px-6 py-6">
            {{ $slot }}
        </div>

        @if ($footer || isset($footerSlot))
            <div class="px-6 py-4 bg-[var(--bg-shell)]/60 border-t border-[var(--border)] flex items-center justify-end gap-3">
                {{ $footer ?? $footerSlot }}
            </div>
        @endif
    </div>
</div>
