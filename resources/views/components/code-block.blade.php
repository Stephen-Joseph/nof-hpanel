@props([
    'title' => null,
    'code' => null,
    'maxHeight' => 'max-h-96',
    'copyable' => true,
])

<div {{ $attributes->merge(['class' => 'rounded-xl overflow-hidden border border-slate-800 bg-slate-950 font-mono text-xs shadow-md']) }}>
    @if ($title || $copyable)
        <div class="flex items-center justify-between px-4 py-2.5 bg-slate-900 border-b border-slate-800 text-slate-400">
            <div class="flex items-center gap-2">
                <span class="flex gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-rose-500/80"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500/80"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500/80"></span>
                </span>
                @if ($title)
                    <span class="ml-2 font-medium text-slate-300">{{ $title }}</span>
                @endif
            </div>

            @if ($copyable)
                <button
                    type="button"
                    x-data
                    @click="window.copyToClipboard($el.closest('.font-mono').querySelector('pre code').innerText)"
                    class="flex items-center gap-1.5 px-2 py-1 rounded text-2xs font-sans text-slate-400 hover:text-white hover:bg-slate-800 transition"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    Copy
                </button>
            @endif
        </div>
    @endif

    <div class="p-4 overflow-x-auto overflow-y-auto {{ $maxHeight }}">
        <pre class="text-slate-200 font-mono leading-relaxed select-text"><code>{{ $code ?? $slot }}</code></pre>
    </div>
</div>

