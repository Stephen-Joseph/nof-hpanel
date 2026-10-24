<x-app-layout>
    <x-slot:title>API Documentation &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <div class="space-y-6">
        <x-layout.page-header 
            title="API Documentation" 
            subtitle="Integrate your billing system, client portal, or custom automation using the v1 REST API."
        >
            <x-slot:actions>
                <x-ui.button href="{{ url('/docs/openapi.yaml') }}" target="_blank" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Download OpenAPI YAML
                </x-ui.button>
            </x-slot:actions>
        </x-layout.page-header>

        <!-- Base URL and Authentication summary card -->
        <x-ui.card class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <h3 class="text-sm font-semibold text-[var(--text)] mb-1">Base URL</h3>
                    <div class="flex items-center gap-2 p-2.5 rounded-xl border border-[var(--border)] bg-[var(--bg-shell)] font-mono text-xs text-[var(--text)]" x-data="{ copied: false }">
                        <span class="truncate flex-1">{{ config('api_docs.base_url') }}</span>
                        <button 
                            type="button" 
                            @click="navigator.clipboard.writeText('{{ config('api_docs.base_url') }}'); copied = true; setTimeout(() => copied = false, 2000)"
                            class="text-xs text-[var(--primary)] hover:underline cursor-pointer"
                        >
                            <span x-text="copied ? 'Copied' : 'Copy'"></span>
                        </button>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-[var(--text)] mb-1">Authentication</h3>
                    <p class="text-xs text-[var(--text-muted)]">
                        Authenticate all requests using the <code class="font-mono text-[var(--text)]">Authorization: Bearer hp_...</code> header. Tokens can be managed on the <a href="{{ route('api.integration.tokens') }}" class="text-blue-500 hover:underline">API Tokens</a> page.
                    </p>
                </div>
            </div>
        </x-ui.card>

        <!-- Two Column Layout: Navigation & Endpoint Cards -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 items-start">
            <!-- Sticky Sidebar Nav -->
            <div class="hidden lg:block lg:col-span-1 sticky top-6">
                <div class="rounded-2xl border border-[var(--border)] bg-[var(--bg-card)] p-4 shadow-xs space-y-4">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)]">Endpoints</h4>
                    <nav class="space-y-1 font-mono text-xs">
                        @foreach (config('api_docs.endpoints', []) as $ep)
                            <a 
                                href="#{{ $ep['id'] }}" 
                                class="flex items-center justify-between px-2.5 py-1.5 rounded-lg text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-shell)] transition-colors"
                            >
                                <span class="truncate">{{ $ep['title'] }}</span>
                                <span class="text-[10px] uppercase font-bold text-[var(--text-muted)] ml-2">{{ $ep['method'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                </div>
            </div>

            <!-- Endpoint Cards List -->
            <div class="lg:col-span-3 space-y-6">
                @foreach (config('api_docs.endpoints', []) as $endpoint)
                    @include('api-integration.partials.endpoint-card', ['endpoint' => $endpoint])
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>

