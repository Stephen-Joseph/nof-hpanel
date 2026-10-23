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

<td {{ $attributes->merge(['class' => "px-6 py-4 whitespace-nowrap $alignClass text-sm text-slate-700 dark:text-slate-300"]) }}>
    {{ $slot }}
</td>

