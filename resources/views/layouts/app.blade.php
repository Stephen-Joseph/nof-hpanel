<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'HPanel') }}</title>

    <!-- Scripts & Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans text-slate-100 antialiased bg-slate-950 flex flex-col">
    <nav class="bg-slate-900 border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-8">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-bold text-lg text-white">
                        <span class="inline-block w-8 h-8 rounded-lg bg-indigo-600 text-white font-black text-xl leading-8 text-center">H</span>
                        {{ config('app.name', 'HPanel') }}
                    </a>

                    <div class="hidden md:flex items-center space-x-4">
                        <a href="{{ route('dashboard') }}" class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('dashboard') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                            Dashboard
                        </a>

                        @can('viewAny', App\Models\AuditLog::class)
                            <a href="{{ route('admin.audit-logs') }}" class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('admin.audit-logs*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                                Audit Logs
                            </a>
                        @endcan
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ Auth::user()?->isAdmin() ? 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' : (Auth::user()?->isReseller() ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-500/10 text-slate-400 border border-slate-500/20') }}">
                        {{ Auth::user()?->role->label() }}
                    </span>

                    <a href="{{ route('profile.security') }}" class="text-sm text-slate-300 hover:text-white">
                        {{ Auth::user()?->name }}
                    </a>

                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="text-sm font-medium text-rose-400 hover:text-rose-300 transition">
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @if (session('status'))
            <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400">
                {{ session('status') }}
            </div>
        @endif

        {{ $slot }}
    </main>

    <footer class="border-t border-slate-800/80 bg-slate-900/50 py-4 text-center text-xs text-slate-500">
        &copy; {{ date('Y') }} {{ config('app.name', 'HPanel') }} &middot; Secure Isolated Web Hosting Platform
    </footer>

    @livewireScripts
</body>
</html>

