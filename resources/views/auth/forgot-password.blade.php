<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-white">Reset Password</h2>
        <p class="text-sm text-slate-400 mt-1">Forgot your password? Enter your email address and we will email you a password reset link.</p>
    </div>

    @if (session('status'))
        <div class="mb-4 text-sm font-medium text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 rounded-lg p-3">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-slate-300">Email Address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
            @error('email')
                <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
            class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">
            Email Password Reset Link
        </button>

        <div class="text-center mt-4">
            <a href="{{ route('login') }}" class="text-xs text-slate-400 hover:text-white transition">
                &larr; Back to login
            </a>
        </div>
    </form>
</x-guest-layout>

