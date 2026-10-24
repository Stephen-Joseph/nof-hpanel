<x-guest-layout>
    <div class="mb-6 text-center">
        <h2 class="text-xl font-bold text-text">Sign in to your account</h2>
        <p class="text-xs text-muted mt-1">Enter your credentials to access the panel</p>
    </div>

    @if (session('status'))
        <div class="mb-4 text-xs font-medium text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 rounded-[16px] p-3">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <x-ui.input
            id="email"
            type="email"
            name="email"
            label="Email Address"
            value="{{ old('email') }}"
            required
            autofocus
        />

        <div>
            <div class="flex items-center justify-between mb-1.5 ml-1">
                <label for="password" class="block text-xs font-semibold uppercase tracking-wider text-muted">Password</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs text-primary-500 hover:text-primary-600 font-medium">
                        Forgot password?
                    </a>
                @endif
            </div>
            <x-ui.input id="password" type="password" name="password" required />
        </div>

        <div class="flex items-center justify-between py-1">
            <label class="flex items-center gap-2 cursor-pointer">
                <input id="remember" type="checkbox" name="remember"
                    class="h-4 w-4 rounded-md border-border bg-card text-primary-500 focus:ring-primary-500">
                <span class="text-xs text-muted">Remember me on this device</span>
            </label>
        </div>

        <x-ui.button type="submit" variant="primary" class="w-full">
            Sign In
        </x-ui.button>
    </form>
</x-guest-layout>
