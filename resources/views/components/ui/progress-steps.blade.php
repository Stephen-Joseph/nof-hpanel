@props([
    'steps' => [],
    'orientation' => 'horizontal', // 'horizontal' | 'vertical'
    'percent' => null,
    'estimatedRemaining' => null,
    'retryAction' => null,
    'title' => null,
    'showProgressBar' => true,
])

@php
    $stepsCollection = collect($steps);
    $totalSteps = $stepsCollection->count();
    $doneSteps = $stepsCollection->where('status', 'done')->count();
    $failedSteps = $stepsCollection->where('status', 'failed')->count();
    $runningSteps = $stepsCollection->where('status', 'running')->count();

    $computedPercent = $percent ?? ($totalSteps > 0 ? (int) round(($doneSteps / $totalSteps) * 100) : 0);
    $hasFailure = $failedSteps > 0;
    $isRunning = $runningSteps > 0;
@endphp

<div {{ $attributes->merge(['class' => 'w-full bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-5 sm:p-6 shadow-xs select-none']) }}>
    <!-- Header: Title, Status Badge, Estimated Remaining -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            @if ($title)
                <h3 class="text-sm font-bold text-[var(--text)] tracking-tight">{{ $title }}</h3>
            @else
                <h3 class="text-sm font-bold text-[var(--text)] tracking-tight">Provisioning Progress</h3>
            @endif
            <p class="text-xs text-[var(--text-muted)] mt-0.5">
                {{ $doneSteps }} of {{ $totalSteps }} stages completed
                @if ($estimatedRemaining !== null && $isRunning)
                    &middot; <span class="font-medium text-blue-500">~{{ $estimatedRemaining }}s remaining</span>
                @endif
            </p>
        </div>

        <div class="flex items-center gap-2">
            @if ($hasFailure)
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20">
                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                    Stage Failed
                </span>
                @if ($retryAction)
                    <button
                        type="button"
                        wire:click="{{ $retryAction }}"
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-600 hover:bg-blue-700 text-white shadow-2xs transition-all cursor-pointer active:scale-95"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span>Retry</span>
                    </button>
                @endif
            @elseif ($isRunning)
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span>
                    In Progress ({{ $computedPercent }}%)
                </span>
            @elseif ($doneSteps === $totalSteps && $totalSteps > 0)
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                    <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    Completed
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-slate-500/10 text-[var(--text-muted)] border border-[var(--border)]">
                    Pending
                </span>
            @endif
        </div>
    </div>

    @if ($showProgressBar)
        <!-- Segmented animated Progress Bar -->
        <div class="relative w-full h-2.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden mb-6">
            <div
                class="h-full bg-gradient-to-r from-blue-600 to-sky-400 rounded-full transition-all duration-500 ease-out"
                style="width: {{ max(2, min(100, $computedPercent)) }}%;"
                role="progressbar"
                aria-valuenow="{{ $computedPercent }}"
                aria-valuemin="0"
                aria-valuemax="100"
            ></div>
        </div>
    @endif

    @if ($orientation === 'horizontal')
        <!-- Horizontal Stepper (Desktop scrollable or grid) -->
        <div class="overflow-x-auto thin-scroll pb-2">
            <div class="flex items-start justify-between min-w-[620px] gap-2">
                @foreach ($stepsCollection as $index => $step)
                    @php
                        $st = is_array($step) ? ($step['status'] ?? 'pending') : $step->status;
                        $label = is_array($step) ? ($step['label'] ?? '') : $step->label;
                        $msg = is_array($step) ? ($step['message'] ?? null) : $step->message;
                        $isLast = $loop->last;
                    @endphp

                    <div class="flex-1 flex flex-col items-center text-center relative group">
                        <!-- Connector line -->
                        @if (! $isLast)
                            <div class="absolute top-4 left-1/2 w-full h-0.5 -translate-y-1/2 z-0 {{ $st === 'done' ? 'bg-blue-500' : 'bg-slate-200 dark:bg-slate-700' }}"></div>
                        @endif

                        <!-- Step Icon Badge -->
                        <div class="relative z-10 w-8 h-8 rounded-full flex items-center justify-center transition-all duration-200
                            @if ($st === 'done')
                                bg-blue-600 text-white shadow-xs ring-4 ring-blue-500/10
                            @elseif ($st === 'running')
                                bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 ring-4 ring-blue-500/20 border-2 border-blue-500
                            @elseif ($st === 'failed')
                                bg-rose-600 text-white shadow-xs ring-4 ring-rose-500/20
                            @else
                                bg-[var(--bg-card)] border-2 border-slate-300 dark:border-slate-600 text-[var(--text-muted)]
                            @endif
                        ">
                            @if ($st === 'done')
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            @elseif ($st === 'running')
                                <svg class="w-4 h-4 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            @elseif ($st === 'failed')
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            @else
                                <span class="w-2 h-2 rounded-full bg-slate-300 dark:bg-slate-600"></span>
                            @endif
                        </div>

                        <!-- Step Label -->
                        <span class="mt-2 text-xs font-semibold line-clamp-1 max-w-[120px]
                            @if ($st === 'done') text-[var(--text)]
                            @elseif ($st === 'running') text-blue-600 dark:text-blue-400 font-bold
                            @elseif ($st === 'failed') text-rose-600 dark:text-rose-400 font-bold
                            @else text-[var(--text-muted)]
                            @endif
                        ">
                            {{ $label }}
                        </span>

                        @if ($msg && ($st === 'running' || $st === 'failed'))
                            <span class="text-[10px] text-[var(--text-muted)] line-clamp-1 max-w-[110px] mt-0.5">
                                {{ $msg }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <!-- Vertical Stepper (Ideal for detail sidebars and mobile) -->
        <div class="space-y-4">
            @foreach ($stepsCollection as $index => $step)
                @php
                    $st = is_array($step) ? ($step['status'] ?? 'pending') : $step->status;
                    $label = is_array($step) ? ($step['label'] ?? '') : $step->label;
                    $msg = is_array($step) ? ($step['message'] ?? null) : $step->message;
                    $isLast = $loop->last;
                @endphp

                <div class="flex items-start gap-3 relative">
                    @if (! $isLast)
                        <div class="absolute top-6 left-3.5 w-0.5 bottom-0 -mb-2 {{ $st === 'done' ? 'bg-blue-500' : 'bg-slate-200 dark:bg-slate-700' }}"></div>
                    @endif

                    <div class="relative z-10 w-7 h-7 rounded-full flex items-center justify-center shrink-0
                        @if ($st === 'done') bg-blue-600 text-white shadow-2xs
                        @elseif ($st === 'running') bg-blue-50 dark:bg-blue-500/10 text-blue-600 border-2 border-blue-500 ring-2 ring-blue-500/20
                        @elseif ($st === 'failed') bg-rose-600 text-white shadow-2xs ring-2 ring-rose-500/20
                        @else bg-[var(--bg-card)] border-2 border-slate-300 dark:border-slate-600 text-[var(--text-muted)]
                        @endif
                    ">
                        @if ($st === 'done')
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        @elseif ($st === 'running')
                            <svg class="w-3.5 h-3.5 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        @elseif ($st === 'failed')
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        @else
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-300 dark:bg-slate-600"></span>
                        @endif
                    </div>

                    <div class="flex-1 min-w-0 pt-0.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold {{ $st === 'running' ? 'text-blue-600 dark:text-blue-400 font-bold' : ($st === 'failed' ? 'text-rose-600 dark:text-rose-400 font-bold' : 'text-[var(--text)]') }}">
                                {{ $label }}
                            </span>
                            @if ($st === 'running')
                                <span class="text-[10px] uppercase font-bold tracking-wider text-blue-500 animate-pulse">Running</span>
                            @elseif ($st === 'failed')
                                <span class="text-[10px] uppercase font-bold tracking-wider text-rose-500">Failed</span>
                            @elseif ($st === 'done')
                                <span class="text-[10px] uppercase font-bold tracking-wider text-emerald-500">Done</span>
                            @endif
                        </div>
                        @if ($msg)
                            <p class="text-[11px] text-[var(--text-muted)] mt-0.5 leading-snug">{{ $msg }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
