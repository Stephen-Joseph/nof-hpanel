<x-app-layout>
    <x-slot:title>Dashboard &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-white tracking-tight">Welcome, {{ Auth::user()->name }}</h1>
        <p class="text-slate-400 mt-1">
            Logged in as <span class="text-white font-medium">{{ Auth::user()->role->label() }}</span> &middot;
            Status: <span class="text-emerald-400 font-medium">{{ ucfirst(Auth::user()->status->value) }}</span>
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-slate-400">Account Role</span>
                <span class="p-2 rounded-lg bg-indigo-500/10 text-indigo-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                </span>
            </div>
            <div class="mt-4 text-2xl font-bold text-white">{{ Auth::user()->role->label() }}</div>
            <p class="mt-1 text-xs text-slate-500">
                @if (Auth::user()->isAdmin())
                    Full system administrative access
                @elseif (Auth::user()->isReseller())
                    Reseller subtree tenancy scope
                @else
                    Isolated customer hosting scope
                @endif
            </p>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-slate-400">Two-Factor Security</span>
                <span class="p-2 rounded-lg {{ Auth::user()->hasTwoFactorEnabled() ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                </span>
            </div>
            <div class="mt-4 text-2xl font-bold {{ Auth::user()->hasTwoFactorEnabled() ? 'text-emerald-400' : 'text-amber-400' }}">
                {{ Auth::user()->hasTwoFactorEnabled() ? 'Protected' : 'Not Enabled' }}
            </div>
            <div class="mt-1">
                <a href="{{ route('profile.security') }}" class="text-xs text-indigo-400 hover:text-indigo-300 underline">
                    Configure security settings &rarr;
                </a>
            </div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-slate-400">Email Status</span>
                <span class="p-2 rounded-lg {{ Auth::user()->hasVerifiedEmail() ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                </span>
            </div>
            <div class="mt-4 text-2xl font-bold {{ Auth::user()->hasVerifiedEmail() ? 'text-emerald-400' : 'text-rose-400' }}">
                {{ Auth::user()->hasVerifiedEmail() ? 'Verified' : 'Unverified' }}
            </div>
            <p class="mt-1 text-xs text-slate-500">{{ Auth::user()->email }}</p>
        </div>
    </div>
</x-app-layout>

