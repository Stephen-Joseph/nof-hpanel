@props([
    'name' => null,
    'label' => null,
    'type' => 'text',
    'hint' => null,
    'error' => null,
    'required' => false,
    'prefixIcon' => null,
    'suffixIcon' => null,
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
        @if (isset($prefix) || $prefixIcon)
            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[var(--text-muted)]">
                @if (isset($prefix))
                    {{ $prefix }}
                @elseif ($prefixIcon === 'search')
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                @else
                    {{ $prefixIcon }}
                @endif
            </div>
        @endif

        <input
            type="{{ $type }}"
            @if($name) name="{{ $name }}" id="{{ $name }}" @endif
            {{ $attributes->merge([
                'class' => 'block w-full h-10 rounded-full border text-sm transition-all duration-150 focus:outline-none focus:ring-2 ' .
                    ($prefixIcon || isset($prefix) ? 'pl-10 ' : 'pl-4 ') .
                    ($suffixIcon || isset($suffix) ? 'pr-10 ' : 'pr-4 ') .
                    ($hasError
                        ? 'border-rose-400 text-rose-900 dark:text-rose-200 placeholder-rose-300 focus:border-rose-500 focus:ring-rose-500/20 bg-rose-50/40 dark:bg-rose-950/20'
                        : 'border-[var(--border)] bg-[var(--bg-card)] text-[var(--text)] placeholder-[var(--text-muted)] focus:border-blue-500 focus:ring-blue-500/20')
            ]) }}
        />

        @if ($suffixIcon || isset($suffix))
            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-[var(--text-muted)]">
                {{ $suffixIcon ?? $suffix }}
            </div>
        @endif
    </div>

    @if ($hasError)
        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400 ml-2">{{ $errorMessage }}</p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-[var(--text-muted)] ml-2">{{ $hint }}</p>
    @endif
</div>
