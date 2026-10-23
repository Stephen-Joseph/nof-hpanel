@props([
    'title' => 'Download our Mobile App',
    'description' => 'Manage your servers & sites on the go',
    'buttonText' => 'Get Mobile App',
    'href' => '#',
])

<div {{ $attributes->merge(['class' => 'rounded-[20px] p-5 bg-card-dark-gradient border border-white/10 text-white relative overflow-hidden shadow-lg group']) }}>
    <!-- Subtle Background Glow -->
    <div class="absolute -top-10 -right-10 w-28 h-28 bg-blue-500/20 rounded-full blur-2xl pointer-events-none group-hover:bg-blue-500/30 transition-all"></div>

    <div class="flex items-center gap-2 mb-3">
        <span class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center text-white shrink-0">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>
        </span>
    </div>

    <h4 class="text-sm font-bold text-white tracking-tight leading-snug">{{ $title }}</h4>
    <p class="text-xs text-slate-300/80 mt-1 leading-relaxed">{{ $description }}</p>

    <div class="mt-4">
        <a
            href="{{ $href }}"
            class="inline-flex items-center justify-center w-full h-8 px-4 rounded-full bg-white hover:bg-slate-100 text-slate-900 font-semibold text-xs transition duration-150 shadow-xs cursor-pointer"
        >
            {{ $buttonText }}
        </a>
    </div>
</div>

