@props([
    'variant' => 'text', // text | circle | card | rect
    'width' => 'w-full',
    'height' => 'h-4',
])

@php
$shapeClasses = match($variant) {
    'circle' => 'rounded-full',
    'card' => 'rounded-[20px]',
    'pill' => 'rounded-full',
    default => 'rounded-lg',
};
@endphp

<div {{ $attributes->merge(['class' => "animate-pulse bg-[var(--bg-shell)] border border-[var(--border)]/60 {$shapeClasses} {$width} {$height}"]) }}></div>

