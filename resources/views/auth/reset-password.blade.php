<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-main">Create New Password</h2>
        <p class="text-xs text-muted mt-1">Please choose a strong new password for your account.</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="block text-xs font-medium text-muted mb-1.5">Email Address</label>
            <x-ui.input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autofocus />
            @error('email')
                <p class="mt-1 text-xs text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-xs font-medium text-muted mb-1.5">New Password</label>
            <x-ui.input id="password" type="password" name="password" required />
            @error('password')
                <p class="mt-1 text-xs text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-xs font-medium text-muted mb-1.5">Confirm Password</label>
            <x-ui.input id="password_confirmation" type="password" name="password_confirmation" required />
            @error('password_confirmation')
                <p class="mt-1 text-xs text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <x-ui.button type="submit" variant="primary" class="w-full">
            Reset Password
        </x-ui.button>
    </form>
</x-guest-layout>
