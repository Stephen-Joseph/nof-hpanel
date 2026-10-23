@props([
    'name' => null,
    'label' => null,
    'hint' => null,
    'error' => null,
    'rows' => 4,
    'monospace' => false,
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

    <div class="relative rounded-[20px] shadow-2xs">
        <textarea
            rows="{{ $rows }}"
            @if($name) name="{{ $name }}" id="{{ $name }}" @endif
            {{ $attributes->merge([
                'class' => 'block w-full rounded-[20px] border text-sm p-4 transition-all duration-150 focus:outline-none focus:ring-2 ' .
                    ($monospace ? 'font-mono text-xs ' : '') .
                    ($hasError
                        ? 'border-rose-400 text-rose-900 dark:text-rose-200 placeholder-rose-300 focus:border-rose-500 focus:ring-rose-500/20 bg-rose-50/40 dark:bg-rose-950/20'
                        : 'border-[var(--border)] bg-[var(--bg-card)] text-[var(--text)] placeholder-[var(--text-muted)] focus:border-blue-500 focus:ring-blue-500/20')
            ]) }}
        >{{ $slot }}</textarea>
    </div>

    @if ($hasError)
        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400 ml-2">{{ $errorMessage }}</p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-[var(--text-muted)] ml-2">{{ $hint }}</p>
    @endif
</div>
