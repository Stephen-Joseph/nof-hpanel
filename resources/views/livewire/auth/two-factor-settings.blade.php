<x-ui.card>
    <div class="flex items-center justify-between pb-4 border-b border-border">
        <div>
            <h3 class="text-base font-semibold text-main">Two-Factor Authentication (TOTP)</h3>
            <p class="text-xs text-muted mt-1">Add additional security to your hosting account using standard TOTP authenticator apps (Google Authenticator, 1Password, Authy).</p>
        </div>
        <div>
            @if ($enabled)
                <x-ui.chip variant="success" size="sm">
                    Active &amp; Enabled
                </x-ui.chip>
            @else
                <x-ui.chip variant="default" size="sm">
                    Not Enabled
                </x-ui.chip>
            @endif
        </div>
    </div>

    <div class="mt-6">
        @if ($enabled)
            <div class="space-y-4">
                <p class="text-xs text-muted">
                    Two-factor authentication is active on your account. When signing in, you will be prompted for a secure 6-digit one-time code.
                </p>

                @if ($showingRecoveryCodes)
                    <div class="mt-4 p-4 rounded-[16px] bg-amber-500/10 border border-amber-500/20">
                        <h4 class="text-xs font-semibold text-amber-600 dark:text-amber-300">Emergency Recovery Codes</h4>
                        <p class="text-[11px] text-amber-700/80 dark:text-amber-200/80 mt-1">Store these recovery codes in a secure password manager. Each code can be used once if you lose access to your authenticator device.</p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-3 font-mono text-xs text-main">
                            @foreach ($recoveryCodes as $recoveryCode)
                                <div class="bg-card px-3 py-1.5 rounded-full border border-border text-center select-all">
                                    {{ $recoveryCode }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="pt-4 flex items-center gap-4">
                    <form wire:submit="disableTwoFactor" class="flex items-center gap-3">
                        <x-ui.input type="password" wire:model="password" placeholder="Confirm current password" />
                        <x-ui.button type="submit" variant="danger" size="sm">
                            Disable 2FA
                        </x-ui.button>
                    </form>
                    @error('password')
                        <span class="text-xs text-rose-500">{{ $message }}</span>
                    @enderror
                </div>
            </div>
        @else
            @if ($showingQrCode)
                <div class="space-y-4">
                    <p class="text-xs text-muted">
                        Scan the setup key using your authenticator app, or manually enter the key below:
                    </p>

                    <div class="p-4 bg-shell rounded-[16px] border border-border max-w-md">
                        <div class="text-xs text-muted">Manual Setup Key:</div>
                        <div class="font-mono text-sm text-primary-500 mt-1 select-all font-semibold tracking-wider">
                            {{ $unconfirmedSecret }}
                        </div>
                        @if ($qrUri)
                            <div class="text-[11px] text-muted mt-2 break-all font-mono">
                                {{ $qrUri }}
                            </div>
                        @endif
                    </div>

                    <form wire:submit="confirmTwoFactor" class="space-y-3 max-w-sm">
                        <label for="code" class="block text-xs font-medium text-muted">Enter the 6-digit code shown in your app to confirm:</label>
                        <div class="flex items-center gap-3">
                            <x-ui.input type="text" wire:model="code" placeholder="123456" maxlength="6" class="tracking-widest text-center font-mono" />
                            <x-ui.button type="submit" variant="primary" size="sm">
                                Confirm &amp; Activate
                            </x-ui.button>
                        </div>
                        @error('code')
                            <p class="text-xs text-rose-500">{{ $message }}</p>
                        @enderror
                    </form>
                </div>
            @else
                <div>
                    <x-ui.button type="button" wire:click="enableTwoFactor" variant="primary" size="sm">
                        Enable Two-Factor Authentication
                    </x-ui.button>
                </div>
            @endif
        @endif
    </div>
</x-ui.card>
