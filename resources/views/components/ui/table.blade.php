@props([
    'headers' => [],
])

<div {{ $attributes->merge(['class' => 'w-full overflow-hidden rounded-[20px] border border-[var(--border)] bg-[var(--bg-card)] shadow-xs dark:shadow-none']) }}>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-[var(--text)] border-collapse">
            @if (count($headers) > 0 || isset($thead))
                <thead class="bg-[var(--bg-shell)]/70 border-b border-[var(--border)] text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wider">
                    @if (isset($thead))
                        {{ $thead }}
                    @else
                        <tr>
                            @foreach ($headers as $header)
                                <th scope="col" class="px-4 sm:px-6 py-3 sm:py-3.5">{{ $header }}</th>
                            @endforeach
                        </tr>
                    @endif
                </thead>
            @endif
            <tbody class="divide-y divide-[var(--border)]">
                {{ $slot }}
            </tbody>
        </table>
    </div>
</div>

