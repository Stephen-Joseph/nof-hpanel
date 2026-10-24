<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-main">Reset Password</h2>
        <p class="text-xs text-muted mt-1">Forgot your password? Enter your email address and we will email you a password reset link.</p>
    </div>

    @if (session('status'))
        <div class="mb-4 text-xs font-medium text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 rounded-[16px] p-3">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-xs font-medium text-muted mb-1.5">Email Address</label>
            <x-ui.input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus />
            @error('email')
                <p class="mt-1 text-xs text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <x-ui.button type="submit" variant="primary" class="w-full">
            Email Password Reset Link
        </x-ui.button>

        <div class="text-center mt-4">
            <a href="{{ route('login') }}" class="text-xs text-muted hover:text-main transition">
                &larr; Back to login
            </a>
        </div>
    </form>
</x-guest-layout>
