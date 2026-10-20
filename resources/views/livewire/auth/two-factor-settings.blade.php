<div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
    <div class="flex items-center justify-between pb-4 border-b border-slate-800">
        <div>
            <h3 class="text-lg font-semibold text-white">Two-Factor Authentication (TOTP)</h3>
            <p class="text-sm text-slate-400 mt-1">Add additional security to your hosting account using standard TOTP authenticator apps (Google Authenticator, 1Password, Authy).</p>
        </div>
        <div>
            @if ($enabled)
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                    Active &amp; Enabled
                </span>
            @else
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-slate-800 text-slate-400 border border-slate-700">
                    Not Enabled
                </span>
            @endif
        </div>
    </div>

    <div class="mt-6">
        @if ($enabled)
            <div class="space-y-4">
                <p class="text-sm text-slate-300">
                    Two-factor authentication is active on your account. When signing in, you will be prompted for a secure 6-digit one-time code.
                </p>

                @if ($showingRecoveryCodes)
                    <div class="mt-4 p-4 rounded-lg bg-amber-500/10 border border-amber-500/20">
                        <h4 class="text-sm font-semibold text-amber-300">Emergency Recovery Codes</h4>
                        <p class="text-xs text-amber-200/80 mt-1">Store these recovery codes in a secure password manager. Each code can be used once if you lose access to your authenticator device.</p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-3 font-mono text-xs text-white">
                            @foreach ($recoveryCodes as $recoveryCode)
                                <div class="bg-slate-800 px-3 py-1.5 rounded border border-slate-700 text-center select-all">
                                    {{ $recoveryCode }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="pt-4 flex items-center gap-4">
                    <form wire:submit="disableTwoFactor" class="flex items-center gap-3">
                        <input type="password" wire:model="password" placeholder="Confirm current password"
                            class="rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-1.5 border">
                        <button type="submit"
                            class="px-4 py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-sm font-medium transition">
                            Disable 2FA
                        </button>
                    </form>
                    @error('password')
                        <span class="text-xs text-rose-400">{{ $message }}</span>
                    @enderror
                </div>
            </div>
        @else
            @if ($showingQrCode)
                <div class="space-y-4">
                    <p class="text-sm text-slate-300">
                        Scan the setup key using your authenticator app, or manually enter the key below:
                    </p>

                    <div class="p-4 bg-slate-800 rounded-lg border border-slate-700 max-w-md">
                        <div class="text-xs text-slate-400">Manual Setup Key:</div>
                        <div class="font-mono text-sm text-indigo-400 mt-1 select-all font-semibold tracking-wider">
                            {{ $unconfirmedSecret }}
                        </div>
                        @if ($qrUri)
                            <div class="text-xs text-slate-500 mt-2 break-all font-mono">
                                {{ $qrUri }}
                            </div>
                        @endif
                    </div>

                    <form wire:submit="confirmTwoFactor" class="space-y-3 max-w-sm">
                        <label for="code" class="block text-xs font-medium text-slate-300">Enter the 6-digit code shown in your app to confirm:</label>
                        <div class="flex items-center gap-3">
                            <input type="text" wire:model="code" placeholder="123456" maxlength="6"
                                class="rounded-lg bg-slate-800 border-slate-700 text-white placeholder-slate-500 text-sm px-3 py-1.5 border tracking-widest text-center font-mono">
                            <button type="submit"
                                class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition">
                                Confirm &amp; Activate
                            </button>
                        </div>
                        @error('code')
                            <p class="text-xs text-rose-400">{{ $message }}</p>
                        @enderror
                    </form>
                </div>
            @else
                <div>
                    <button type="button" wire:click="enableTwoFactor"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition">
                        Enable Two-Factor Authentication
                    </button>
                </div>
            @endif
        @endif
    </div>
</div>

