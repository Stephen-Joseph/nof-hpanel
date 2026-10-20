<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, AuditService $audit): RedirectResponse
    {
        $user = $request->authenticate();

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put([
                'login.id' => $user->id,
                'login.remember' => $request->boolean('remember'),
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $audit->log('auth.login', $user);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Display the 2FA challenge view.
     */
    public function twoFactorChallenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    /**
     * Handle an incoming two-factor authentication challenge.
     */
    public function twoFactorStore(
        TwoFactorChallengeRequest $request,
        TotpService $totp,
        AuditService $audit
    ): RedirectResponse {
        $userId = $request->session()->get('login.id');

        if (! $userId) {
            return redirect()->route('login');
        }

        /** @var User|null $user */
        $user = User::find($userId);

        if (! $user) {
            $request->session()->forget(['login.id', 'login.remember']);

            return redirect()->route('login');
        }

        $isValid = false;

        if ($request->filled('code') && $user->two_factor_secret) {
            $isValid = $totp->verifyCode($user->two_factor_secret, (string) $request->input('code'));
        } elseif ($request->filled('recovery_code')) {
            $isValid = $totp->verifyRecoveryCode($user, (string) $request->input('recovery_code'));
        }

        if (! $isValid) {
            throw ValidationException::withMessages([
                'code' => ['The provided two-factor authentication code or recovery code is invalid.'],
            ]);
        }

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        $audit->log('auth.2fa.login', $user);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, AuditService $audit): RedirectResponse
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user) {
            $audit->log('auth.logout', $user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
