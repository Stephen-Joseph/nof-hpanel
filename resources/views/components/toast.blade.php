<div
    x-data="{
        toasts: [],
        add(toast) {
            toast.id = Date.now() + Math.random();
            toast.type = toast.type || 'info';
            this.toasts.push(toast);
            setTimeout(() => this.remove(toast.id), toast.duration || 4500);
        },
        remove(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        }
    }"
    x-init="
        window.addEventListener('notify', (event) => add(event.detail));
        window.addEventListener('toast', (event) => add(event.detail));
        @if (session('status') || session('success'))
            add({ type: 'success', message: '{{ session('status') ?? session('success') }}' });
        @endif
        @if (session('error'))
            add({ type: 'danger', message: '{{ session('error') }}' });
        @endif
        @if (session('warning'))
            add({ type: 'warning', message: '{{ session('warning') }}' });
        @endif
    "
    class="fixed bottom-6 right-6 z-50 flex flex-col gap-2.5 max-w-sm w-full pointer-events-none"
    aria-live="polite"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="true"
            x-transition:enter="transform ease-out duration-300 transition"
            x-transition:enter-start="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
            x-transition:enter-end="translate-y-0 opacity-100 sm:translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="pointer-events-auto flex items-start gap-3 p-4 rounded-[20px] border border-[var(--border)] bg-[var(--bg-card)] shadow-xl backdrop-blur-md transition-all"
        >
            <div class="shrink-0 mt-0.5">
                <template x-if="toast.type === 'success'">
                    <span class="w-7 h-7 rounded-full bg-[var(--status-success-bg)] text-[var(--status-success-text)] flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                    </span>
                </template>
                <template x-if="toast.type === 'danger'">
                    <span class="w-7 h-7 rounded-full bg-[var(--status-danger-bg)] text-[var(--status-danger-text)] flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </span>
                </template>
                <template x-if="toast.type === 'warning'">
                    <span class="w-7 h-7 rounded-full bg-[var(--status-warning-bg)] text-[var(--status-warning-text)] flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </span>
                </template>
                <template x-if="toast.type === 'info'">
                    <span class="w-7 h-7 rounded-full bg-[var(--status-info-bg)] text-[var(--status-info-text)] flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </span>
                </template>
            </div>

            <div class="flex-1 text-sm font-medium text-[var(--text)] leading-snug pt-0.5" x-text="toast.message"></div>

            <button
                type="button"
                @click="remove(toast.id)"
                class="shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-shell)] transition"
                aria-label="Dismiss toast"
            >
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </template>
</div>
