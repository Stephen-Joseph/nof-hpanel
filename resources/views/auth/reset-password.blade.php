<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-white">Create New Password</h2>
        <p class="text-sm text-slate-400 mt-1">Please choose a strong new password for your account.</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="block text-sm font-medium text-slate-300">Email Address</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autofocus
                class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
            @error('email')
                <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-slate-300">New Password</label>
            <input id="password" type="password" name="password" required
                class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
            @error('password')
                <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-slate-300">Confirm Password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required
                class="mt-1 block w-full rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border">
            @error('password_confirmation')
                <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
            class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">
            Reset Password
        </button>
    </form>
</x-guest-layout>

