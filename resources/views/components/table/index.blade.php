@props([
    'pagination' => null,
])

<div class="overflow-hidden border border-slate-200 dark:border-slate-800 rounded-xl shadow-xs bg-white dark:bg-slate-900">
    <div class="overflow-x-auto">
        <table {{ $attributes->merge(['class' => 'min-w-full divide-y divide-slate-200 dark:divide-slate-800']) }}>
            {{ $slot }}
        </table>
    </div>

    @if ($pagination)
        <div class="px-6 py-3 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/50">
            {{ $pagination }}
        </div>
    @endif
</div>

