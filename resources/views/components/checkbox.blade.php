@props([
    'name' => null,
    'label' => null,
    'description' => null,
    'error' => null,
])

@php
$hasError = $error || (isset($errors) && $name && $errors->has($name));
$errorMessage = $error ?? (isset($errors) && $name ? $errors->first($name) : null);
@endphp

<div>
    <label class="flex items-start gap-3 cursor-pointer group">
        <input
            type="checkbox"
            @if($name) name="{{ $name }}" id="{{ $name }}" @endif
            {{ $attributes->merge([
                'class' => 'mt-0.5 h-4 w-4 rounded border-slate-300 dark:border-slate-700 text-indigo-600 focus:ring-indigo-500 bg-white dark:bg-slate-900 transition'
            ]) }}
        />
        @if ($label || $description)
            <div>
                @if ($label)
                    <span class="text-sm font-medium text-slate-800 dark:text-slate-200 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors">
                        {{ $label }}
                    </span>
                @endif
                @if ($description)
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $description }}</p>
                @endif
            </div>
        @endif
    </label>

    @if ($hasError)
        <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $errorMessage }}</p>
    @endif
</div>
