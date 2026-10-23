@props([
    'lines' => 3,
    'height' => 'h-4',
    'circle' => false,
])

<div {{ $attributes->merge(['class' => 'animate-pulse space-y-3']) }}>
    @if ($circle)
        <div class="rounded-full bg-slate-200 dark:bg-slate-800 {{ $height }} w-{{ substr($height, 2) }}"></div>
    @else
        @for ($i = 0; $i < $lines; $i++)
            <div class="rounded-md bg-slate-200 dark:bg-slate-800 {{ $height }} {{ $i === $lines - 1 && $lines > 1 ? 'w-2/3' : 'w-full' }}"></div>
        @endfor
    @endif
</div>

