<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-white">Two-Factor Authentication</h2>
        <p class="text-sm text-slate-400 mt-1">Please enter the 6-digit authentication code from your authenticator app, or use an emergency recovery code.</p>
    </div>

    <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4" x-data="{ recovery: false }">
        @csrf

        <div x-show="!recovery">
            <label for="code" class="block text-sm font-medium text-slate-300">Authentication Code</label>
            <input id="code" type="text" inputmode="numeric" name="code" autofocus autocomplete="one-time-code"
                placeholder="123456"
                class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border tracking-widest text-center font-mono text-lg">
            @error('code')
                <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        <div x-show="recovery" style="display: none;">
            <label for="recovery_code" class="block text-sm font-medium text-slate-300">Emergency Recovery Code</label>
            <input id="recovery_code" type="text" name="recovery_code" autocomplete="one-time-code"
                placeholder="Enter 10-character code"
                class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border font-mono text-center">
            @error('recovery_code')
                <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between text-xs">
            <button type="button" class="text-indigo-400 hover:text-indigo-300 underline"
                x-show="!recovery"
                x-on:click="recovery = true; $nextTick(() => document.getElementById('recovery_code').focus());">
                Use a recovery code instead
            </button>

            <button type="button" class="text-indigo-400 hover:text-indigo-300 underline"
                x-show="recovery"
                style="display: none;"
                x-on:click="recovery = false; $nextTick(() => document.getElementById('code').focus());">
                Use an authentication code instead
            </button>
        </div>

        <button type="submit"
            class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">
            Verify &amp; Continue
        </button>
    </form>
</x-guest-layout>

