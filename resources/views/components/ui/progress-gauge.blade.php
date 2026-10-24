@props([
    'title' => 'Project Progress',
    'percentage' => 41,
    'label' => 'Project Ended',
    'completed' => 25,
    'inProgress' => 16,
    'pending' => 59,
])

<x-ui.card :title="$title">
    <div class="flex flex-col items-center justify-center pt-2 pb-1">
        <!-- SVG Semi-Circle Gauge -->
        <div class="relative w-56 h-32 flex items-end justify-center overflow-hidden">
            <svg viewBox="0 0 200 115" class="w-full h-full overflow-visible">
                <defs>
                    <pattern id="gaugeHatchPattern" width="6" height="6" patternTransform="rotate(45 0 0)" patternUnits="userSpaceOnUse">
                        <line x1="0" y1="0" x2="0" y2="6" stroke="currentColor" stroke-width="2" class="text-slate-300 dark:text-slate-700" />
                    </pattern>
                </defs>

                <!-- Background Track (Hatch Pattern) -->
                <path
                    d="M 25 105 A 75 75 0 0 1 175 105"
                    fill="none"
                    stroke="url(#gaugeHatchPattern)"
                    stroke-width="22"
                    stroke-linecap="round"
                />

                <!-- In Progress Arc (Solid Medium Blue) -->
                <path
                    d="M 25 105 A 75 75 0 0 1 175 105"
                    fill="none"
                    stroke="var(--primary-500)"
                    stroke-width="22"
                    stroke-linecap="round"
                    stroke-dasharray="236"
                    stroke-dashoffset="{{ 236 - (236 * ($percentage / 100)) }}"
                    class="transition-all duration-700 ease-out"
                />

                <!-- Completed Arc (Solid Dark Blue) -->
                <path
                    d="M 25 105 A 75 75 0 0 1 175 105"
                    fill="none"
                    stroke="var(--primary-700)"
                    stroke-width="22"
                    stroke-linecap="round"
                    stroke-dasharray="236"
                    stroke-dashoffset="{{ 236 - (236 * ($completed / 100)) }}"
                    class="transition-all duration-700 ease-out"
                />
            </svg>

            <!-- Center Metric Readout -->
            <div class="absolute bottom-1 flex flex-col items-center text-center">
                <span class="text-3.5xl sm:text-4xl font-extrabold text-[var(--text)] tracking-tight leading-none">
                    {{ $percentage }}%
                </span>
                <span class="text-xs text-[var(--text-muted)] font-medium mt-1">
                    {{ $label }}
                </span>
            </div>
        </div>

        <!-- Legend Row -->
        <div class="flex items-center justify-center gap-4 mt-6 pt-3 border-t border-[var(--border)] w-full text-xs text-[var(--text-muted)]">
            <div class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full bg-blue-900 dark:bg-blue-800 shrink-0"></span>
                <span>Completed</span>
            </div>

            <div class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full bg-blue-600 dark:bg-blue-500 shrink-0"></span>
                <span>In Progress</span>
            </div>

            <div class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-xs bg-hatch border border-[var(--border)] shrink-0"></span>
                <span>Pending</span>
            </div>
        </div>
    </div>
</x-ui.card>

