@props(['endpoint'])

@php
    $methodColors = match(strtoupper($endpoint['method'])) {
        'GET' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
        'POST' => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
        'PUT', 'PATCH' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
        'DELETE' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
        default => 'bg-slate-500/10 text-slate-600 border-slate-500/20',
    };
@endphp

<div id="{{ $endpoint['id'] }}" class="scroll-mt-6 rounded-2xl border border-[var(--border)] bg-[var(--bg-card)] shadow-xs overflow-hidden transition-all duration-150">
    <!-- Header -->
    <div class="p-5 border-b border-[var(--border)] flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <span class="px-2.5 py-1 text-xs font-bold font-mono uppercase tracking-wider rounded-md border {{ $methodColors }}">
                {{ $endpoint['method'] }}
            </span>
            <code class="text-sm font-semibold font-mono text-[var(--text)]">
                {{ $endpoint['path'] }}
            </code>
        </div>
        @if (!empty($endpoint['scope']))
            <div class="flex items-center gap-1.5 text-xs text-[var(--text-muted)]">
                <span class="font-medium">Scope:</span>
                <span class="px-2 py-0.5 rounded font-mono text-[11px] bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)]">
                    {{ $endpoint['scope'] }}
                </span>
            </div>
        @endif
    </div>

    <!-- Body -->
    <div class="p-5 space-y-4">
        <div>
            <h4 class="text-base font-semibold text-[var(--text)] mb-1">{{ $endpoint['title'] }}</h4>
            <p class="text-xs text-[var(--text-muted)] leading-relaxed">{{ $endpoint['description'] }}</p>
        </div>

        <!-- Headers -->
        @if (!empty($endpoint['headers']))
            <div class="space-y-1.5">
                <span class="text-xs font-semibold text-[var(--text)] uppercase tracking-wider">Headers</span>
                <div class="rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] p-3 font-mono text-xs space-y-1">
                    @foreach ($endpoint['headers'] as $headerKey => $headerVal)
                        <div class="flex items-center gap-2">
                            <span class="text-blue-500 dark:text-blue-400 font-semibold">{{ $headerKey }}:</span>
                            <span class="text-[var(--text-muted)]">{{ $headerVal }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Request Body -->
        @if (!empty($endpoint['request_body']))
            <div class="space-y-1.5">
                <span class="text-xs font-semibold text-[var(--text)] uppercase tracking-wider">Request Body (JSON)</span>
                <pre class="rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] p-3.5 font-mono text-xs text-[var(--text)] overflow-x-auto"><code>{{ json_encode($endpoint['request_body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
            </div>
        @endif

        <!-- Response Example -->
        @if (!empty($endpoint['response']))
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-[var(--text)] uppercase tracking-wider">Response Example</span>
                    <span class="text-xs font-mono font-bold text-emerald-600 dark:text-emerald-400">HTTP {{ $endpoint['response']['status'] }}</span>
                </div>
                <pre class="rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] p-3.5 font-mono text-xs text-[var(--text)] overflow-x-auto"><code>{{ json_encode($endpoint['response']['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
            </div>
        @endif

        <!-- Code Samples Tabs -->
        @if (!empty($endpoint['samples']))
            <div x-data="{ tab: 'curl', copied: false }" class="space-y-2 pt-2 border-t border-[var(--border)]">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-1 p-1 rounded-xl bg-[var(--bg-shell)] border border-[var(--border)]">
                        <button
                            type="button"
                            @click="tab = 'curl'"
                            :class="tab === 'curl' ? 'bg-[var(--bg-card)] text-[var(--text)] font-semibold shadow-2xs' : 'text-[var(--text-muted)] hover:text-[var(--text)]'"
                            class="px-2.5 py-1 text-xs rounded-lg transition-all"
                        >
                            cURL
                        </button>
                        <button
                            type="button"
                            @click="tab = 'php'"
                            :class="tab === 'php' ? 'bg-[var(--bg-card)] text-[var(--text)] font-semibold shadow-2xs' : 'text-[var(--text-muted)] hover:text-[var(--text)]'"
                            class="px-2.5 py-1 text-xs rounded-lg transition-all"
                        >
                            PHP
                        </button>
                        <button
                            type="button"
                            @click="tab = 'nodejs'"
                            :class="tab === 'nodejs' ? 'bg-[var(--bg-card)] text-[var(--text)] font-semibold shadow-2xs' : 'text-[var(--text-muted)] hover:text-[var(--text)]'"
                            class="px-2.5 py-1 text-xs rounded-lg transition-all"
                        >
                            Node.js
                        </button>
                    </div>

                    <button
                        type="button"
                        @click="
                            const code = tab === 'curl' ? @js($endpoint['samples']['curl']) : (tab === 'php' ? @js($endpoint['samples']['php']) : @js($endpoint['samples']['nodejs']));
                            navigator.clipboard.writeText(code);
                            copied = true;
                            setTimeout(() => copied = false, 2000);
                        "
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text)] rounded-lg hover:bg-[var(--bg-shell)] transition-all cursor-pointer"
                    >
                        <svg x-show="!copied" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        <svg x-show="copied" x-cloak class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        <span x-text="copied ? 'Copied!' : 'Copy Code'"></span>
                    </button>
                </div>

                <div class="relative rounded-xl border border-[var(--border)] bg-slate-900 text-slate-100 p-4 font-mono text-xs overflow-x-auto">
                    <pre x-show="tab === 'curl'"><code>{{ $endpoint['samples']['curl'] }}</code></pre>
                    <pre x-show="tab === 'php'" x-cloak><code>{{ $endpoint['samples']['php'] }}</code></pre>
                    <pre x-show="tab === 'nodejs'" x-cloak><code>{{ $endpoint['samples']['nodejs'] }}</code></pre>
                </div>
            </div>
        @endif
    </div>
</div>

