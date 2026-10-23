@props([
    'align' => 'left',
])

@php
$alignClass = match($align) {
    'right' => 'text-right',
    'center' => 'text-center',
    default => 'text-left',
};
@endphp

<th scope="col" {{ $attributes->merge(['class' => "px-6 py-3.5 $alignClass text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider"]) }}>
    {{ $slot }}
</th>

