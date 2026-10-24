<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-main">Verify Your Email Address</h2>
        <p class="text-xs text-muted mt-2">
            Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn't receive the email, we will gladly send you another.
        </p>
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 text-xs font-medium text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 rounded-[16px] p-3">
            A new verification link has been sent to the email address you provided.
        </div>
    @endif

    <div class="space-y-4">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button type="submit" variant="primary" class="w-full">
                Resend Verification Email
            </x-ui.button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <button type="submit" class="text-xs text-muted hover:text-main underline cursor-pointer">
                Log Out
            </button>
        </form>
    </div>
</x-guest-layout>
