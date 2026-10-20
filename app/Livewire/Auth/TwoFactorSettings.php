<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\AuditService;
use App\Services\TotpService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

class TwoFactorSettings extends Component
{
    public bool $showingQrCode = false;

    public bool $showingRecoveryCodes = false;

    public string $code = '';

    public string $unconfirmedSecret = '';

    public string $password = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public function enableTwoFactor(TotpService $totp): void
    {
        $this->unconfirmedSecret = $totp->generateSecret();
        $this->showingQrCode = true;
        $this->code = '';
    }

    public function confirmTwoFactor(TotpService $totp, AuditService $audit): void
    {
        $this->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        if (! $totp->verifyCode($this->unconfirmedSecret, $this->code)) {
            throw ValidationException::withMessages([
                'code' => 'The provided two-factor code was invalid.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();
        $recoveryCodes = $totp->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $this->unconfirmedSecret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->recoveryCodes = $recoveryCodes;
        $this->showingQrCode = false;
        $this->showingRecoveryCodes = true;
        $this->code = '';
        $this->unconfirmedSecret = '';

        $audit->log('auth.2fa.enabled', $user);
    }

    public function disableTwoFactor(AuditService $audit): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        if (! Hash::check($this->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The password provided does not match your current password.',
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->password = '';
        $this->showingRecoveryCodes = false;
        $this->showingQrCode = false;

        $audit->log('auth.2fa.disabled', $user);
    }

    public function render(TotpService $totp): View
    {
        /** @var User $user */
        $user = Auth::user();
        $qrUri = null;

        if ($this->showingQrCode && $this->unconfirmedSecret) {
            $qrUri = $totp->getOtpauthUri(
                config('app.name', 'HPanel'),
                $user->email,
                $this->unconfirmedSecret
            );
        }

        return view('livewire.auth.two-factor-settings', [
            'user' => $user,
            'enabled' => $user->hasTwoFactorEnabled(),
            'qrUri' => $qrUri,
        ]);
    }
}
