<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('panel:create-admin creates a new verified administrator account idempotently', function () {
    $this->artisan('panel:create-admin', [
        'email' => 'admin@example.com',
        'password' => 'SuperSecret123!',
    ])->assertExitCode(0);

    $user = User::where('email', 'admin@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::ADMIN)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('SuperSecret123!', $user->password))->toBeTrue();

    // Re-running updates password and stays admin
    $this->artisan('panel:create-admin', [
        'email' => 'admin@example.com',
        'password' => 'NewPassword456!',
    ])->assertExitCode(0);

    $user->refresh();
    expect(Hash::check('NewPassword456!', $user->password))->toBeTrue()
        ->and($user->role)->toBe(UserRole::ADMIN);
});

test('panel:create-admin validates email and minimum password length', function () {
    $this->artisan('panel:create-admin', [
        'email' => 'invalid-email',
        'password' => 'short',
    ])->assertExitCode(1);

    expect(User::where('email', 'invalid-email')->exists())->toBeFalse();
});
