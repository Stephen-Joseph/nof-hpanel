@props([
    'active' => null,
    'tabs' => [],
])

<div {{ $attributes->merge(['class' => 'inline-flex p-1 rounded-full bg-[var(--bg-shell)] border border-[var(--border)] gap-1']) }}>
    @foreach ($tabs as $key => $label)
        <button
            type="button"
            @if ($active)
                @click="$dispatch('tab-selected', '{{ $key }}')"
                class="px-4 py-1.5 rounded-full text-xs font-semibold transition-all duration-150 {{ $active === $key ? 'bg-[var(--bg-card)] text-[var(--text)] shadow-xs' : 'text-[var(--text-muted)] hover:text-[var(--text)]' }}"
            @else
                class="px-4 py-1.5 rounded-full text-xs font-semibold text-[var(--text-muted)] hover:text-[var(--text)] transition-all duration-150"
            @endif
        >
            {{ $label }}
        </button>
    @endforeach
    {{ $slot }}
</div>

