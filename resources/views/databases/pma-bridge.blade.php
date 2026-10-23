<x-app-layout>
    <x-slot:title>phpMyAdmin SSO Launch &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <div class="max-w-xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
        <div class="bg-slate-900 border border-slate-800 shadow-xl rounded-2xl p-8 text-center">
            <div class="w-16 h-16 bg-amber-500/10 text-amber-400 rounded-full flex items-center justify-center mx-auto mb-4 border border-amber-500/20">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                </svg>
            </div>

            <h2 class="text-2xl font-bold text-white mb-2">phpMyAdmin SSO Authenticated</h2>
            <p class="text-sm text-slate-400 mb-6">
                Single-sign-on token validated for user <strong class="text-white font-mono">{{ $databaseUser->username }}</strong>.
                @if($targetDb)
                    Opening database <strong class="text-white font-mono">{{ $targetDb }}</strong>.
                @endif
            </p>

            <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 text-left text-xs font-mono space-y-1.5 text-slate-400 mb-6">
                <div><span class="text-slate-500">Server:</span> {{ $account->server->hostname }}</div>
                <div><span class="text-slate-500">Database User:</span> {{ $databaseUser->username }}@'{{ $databaseUser->remote_host }}'</div>
                <div><span class="text-slate-500">Target Database:</span> {{ $targetDb ?? '(All authorized)' }}</div>
                <div><span class="text-slate-500">Session Status:</span> Single-use token consumed</div>
            </div>

            <div class="flex items-center justify-center gap-3">
                <a href="{{ route('accounts.databases', $account) }}" class="inline-flex items-center px-4 py-2 border border-slate-700 rounded-lg text-xs font-semibold text-slate-300 hover:text-white hover:bg-slate-800 transition">
                    Return to Databases
                </a>
                <button type="button" onclick="alert('In production, this connects to the isolated phpMyAdmin instance on https://pma.{{ $account->server->hostname }}/');" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-lg shadow transition">
                    Open Database Console
                </button>
            </div>
        </div>
    </div>
</x-app-layout>

