@props([
    'name' => null,
    'label' => null,
    'description' => null,
    'disabled' => false,
])

<label class="flex items-start justify-between cursor-pointer group">
    @if ($label || $description)
        <div class="mr-4">
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

    <div class="relative inline-flex items-center shrink-0">
        <input
            type="checkbox"
            @if($name) name="{{ $name }}" id="{{ $name }}" @endif
            @if($disabled) disabled @endif
            {{ $attributes->merge(['class' => 'sr-only peer']) }}
        />
        <div class="w-11 h-6 bg-slate-200 dark:bg-slate-700 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-500 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
    </div>
</label>

