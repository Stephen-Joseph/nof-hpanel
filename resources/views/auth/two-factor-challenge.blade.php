<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-main">Two-Factor Authentication</h2>
        <p class="text-xs text-muted mt-1">Please enter the 6-digit authentication code from your authenticator app, or use an emergency recovery code.</p>
    </div>

    <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4" x-data="{ recovery: false }">
        @csrf

        <div x-show="!recovery">
            <label for="code" class="block text-xs font-medium text-muted mb-1.5">Authentication Code</label>
            <x-ui.input id="code" type="text" inputmode="numeric" name="code" autofocus autocomplete="one-time-code"
                placeholder="123456" class="tracking-widest text-center font-mono text-base" />
            @error('code')
                <p class="mt-1 text-xs text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <div x-show="recovery" style="display: none;">
            <label for="recovery_code" class="block text-xs font-medium text-muted mb-1.5">Emergency Recovery Code</label>
            <x-ui.input id="recovery_code" type="text" name="recovery_code" autocomplete="one-time-code"
                placeholder="Enter 10-character code" class="font-mono text-center text-xs" />
            @error('recovery_code')
                <p class="mt-1 text-xs text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between text-xs">
            <button type="button" class="text-primary-500 hover:text-primary-600 underline cursor-pointer"
                x-show="!recovery"
                x-on:click="recovery = true; $nextTick(() => document.getElementById('recovery_code').focus());">
                Use a recovery code instead
            </button>

            <button type="button" class="text-primary-500 hover:text-primary-600 underline cursor-pointer"
                x-show="recovery"
                style="display: none;"
                x-on:click="recovery = false; $nextTick(() => document.getElementById('code').focus());">
                Use an authentication code instead
            </button>
        </div>

        <x-ui.button type="submit" variant="primary" class="w-full">
            Verify &amp; Continue
        </x-ui.button>
    </form>
</x-guest-layout>
