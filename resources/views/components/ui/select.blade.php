@props([
    'name' => null,
    'label' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
])

@php
$hasError = $error || (isset($errors) && $name && $errors->has($name));
$errorMessage = $error ?? (isset($errors) && $name ? $errors->first($name) : null);
@endphp

<div class="w-full">
    @if ($label)
        <label @if($name) for="{{ $name }}" @endif class="block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-1.5 ml-1">
            {{ $label }}
            @if ($required)
                <span class="text-rose-500">*</span>
            @endif
        </label>
    @endif

    <div class="relative rounded-full shadow-2xs">
        <select
            @if($name) name="{{ $name }}" id="{{ $name }}" @endif
            {{ $attributes->merge([
                'class' => 'appearance-none block w-full h-10 rounded-full border text-sm pl-4 pr-10 transition-all duration-150 focus:outline-none focus:ring-2 cursor-pointer ' .
                    ($hasError
                        ? 'border-rose-400 text-rose-900 dark:text-rose-200 focus:border-rose-500 focus:ring-rose-500/20 bg-rose-50/40 dark:bg-rose-950/20'
                        : 'border-[var(--border)] bg-[var(--bg-card)] text-[var(--text)] focus:border-blue-500 focus:ring-blue-500/20')
            ]) }}
        >
            {{ $slot }}
        </select>

        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-[var(--text-muted)]">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </div>
    </div>

    @if ($hasError)
        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400 ml-2">{{ $errorMessage }}</p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-[var(--text-muted)] ml-2">{{ $hint }}</p>
    @endif
</div>

