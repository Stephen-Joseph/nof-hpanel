@props(['domain', 'active' => 'pointing'])

@php
    $tabs = [
        [
            'key' => 'pointing',
            'label' => 'Pointing',
            'route' => route('domains.dns', $domain),
            'can' => auth()->user()?->can('view', $domain),
        ],
        [
            'key' => 'ssl',
            'label' => 'SSL',
            'route' => route('domains.ssl', $domain),
            'can' => auth()->user()?->can('view', $domain),
        ],
        [
            'key' => 'nginx',
            'label' => 'Nginx config',
            'route' => route('domains.nginx', $domain),
            'can' => auth()->user()?->can('update', $domain),
        ],
        [
            'key' => 'php',
            'label' => 'PHP',
            'route' => route('domains.php', $domain),
            'can' => auth()->user()?->can('view', $domain),
        ],
        [
            'key' => 'redirects',
            'label' => 'Redirects',
            'route' => route('domains.redirects', $domain),
            'can' => auth()->user()?->can('update', $domain),
        ],
    ];
@endphp

<div class="mb-6 border-b border-[var(--border)]">
    <nav class="-mb-px flex flex-wrap gap-2 sm:gap-6" aria-label="Domain tabs">
        @foreach ($tabs as $tab)
            @if ($tab['can'])
                @php $isActive = $active === $tab['key']; @endphp
                <a
                    href="{{ $tab['route'] }}"
                    @class([
                        'whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors',
                        'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' => $isActive,
                        'border-transparent text-[var(--text-muted)] hover:text-[var(--text)] hover:border-[var(--border)]' => ! $isActive,
                    ])
                >
                    {{ $tab['label'] }}
                </a>
            @endif
        @endforeach
    </nav>
</div>
