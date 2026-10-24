<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-main">Sign in to your account</h2>
        <p class="text-xs text-muted mt-1">Enter your credentials to access the panel</p>
    </div>

    @if (session('status'))
        <div class="mb-4 text-xs font-medium text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 rounded-[16px] p-3">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-xs font-medium text-muted mb-1.5">Email Address</label>
            <x-ui.input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus />
            @error('email')
                <p class="mt-1 text-xs text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <div class="flex items-center justify-between mb-1.5">
                <label for="password" class="block text-xs font-medium text-muted">Password</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs text-primary-500 hover:text-primary-600">
                        Forgot password?
                    </a>
                @endif
            </div>
            <x-ui.input id="password" type="password" name="password" required />
            @error('password')
                <p class="mt-1 text-xs text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center">
            <input id="remember" type="checkbox" name="remember"
                class="h-4 w-4 rounded-sm bg-card border-border text-primary-500 focus:ring-primary-500">
            <label for="remember" class="ml-2 block text-xs text-muted cursor-pointer">Remember me on this device</label>
        </div>

        <x-ui.button type="submit" variant="primary" class="w-full">
            Sign In
        </x-ui.button>
    </form>
</x-guest-layout>
