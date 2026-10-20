<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-white">Sign in to your account</h2>
        <p class="text-sm text-slate-400 mt-1">Enter your credentials to access the panel</p>
    </div>

    @if (session('status'))
        <div class="mb-4 text-sm font-medium text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 rounded-lg p-3">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-slate-300">Email Address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
            @error('email')
                <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <div class="flex items-center justify-between">
                <label for="password" class="block text-sm font-medium text-slate-300">Password</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs text-indigo-400 hover:text-indigo-300">
                        Forgot password?
                    </a>
                @endif
            </div>
            <input id="password" type="password" name="password" required
                class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
            @error('password')
                <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center">
            <input id="remember" type="checkbox" name="remember"
                class="h-4 w-4 rounded bg-slate-800 border-slate-700 text-indigo-600 focus:ring-indigo-500">
            <label for="remember" class="ml-2 block text-xs text-slate-400">Remember me on this device</label>
        </div>

        <button type="submit"
            class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">
            Sign In
        </button>
    </form>
</x-guest-layout>

