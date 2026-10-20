<?php

use App\Livewire\Auth\TwoFactorSettings;
use App\Models\User;
use App\Services\TotpService;
use Livewire\Livewire;

test('user with two factor enabled is redirected to challenge', function () {
    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('two-factor.login'));
    $this->assertTrue(session()->has('login.id'));
});

test('user can authenticate with valid totp code', function () {
    $totp = app(TotpService::class);
    $secret = $totp->generateSecret();
    $user = User::factory()->withTwoFactor($secret)->create();

    $validCode = $totp->getCode($secret);

    $this->withSession(['login.id' => $user->id])
        ->post(route('two-factor.login.store'), [
            'code' => $validCode,
        ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('user cannot authenticate with invalid totp code', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->withSession(['login.id' => $user->id])
        ->post(route('two-factor.login.store'), [
            'code' => '000000',
        ])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('user can authenticate with recovery code and it is consumed', function () {
    $user = User::factory()->withTwoFactor('JBSWY3DPEHPK3PXP', ['VALIDCODE1', 'VALIDCODE2'])->create();

    $this->withSession(['login.id' => $user->id])
        ->post(route('two-factor.login.store'), [
            'recovery_code' => 'VALIDCODE1',
        ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    $user->refresh();
    $this->assertNotContains('VALIDCODE1', $user->two_factor_recovery_codes);
    $this->assertContains('VALIDCODE2', $user->two_factor_recovery_codes);
});

test('user can enable and confirm two factor via livewire', function () {
    $user = User::factory()->create();
    $totp = app(TotpService::class);

    $this->actingAs($user);

    $component = Livewire::test(TwoFactorSettings::class)
        ->call('enableTwoFactor');

    $secret = $component->get('unconfirmedSecret');
    $this->assertNotEmpty($secret);

    $validCode = $totp->getCode($secret);

    $component->set('code', $validCode)
        ->call('confirmTwoFactor')
        ->assertHasNoErrors();

    $user->refresh();
    $this->assertTrue($user->hasTwoFactorEnabled());
    $this->assertNotEmpty($user->two_factor_recovery_codes);
});

test('user can disable two factor with correct password', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user);

    Livewire::test(TwoFactorSettings::class)
        ->set('password', 'password')
        ->call('disableTwoFactor')
        ->assertHasNoErrors();

    $user->refresh();
    $this->assertFalse($user->hasTwoFactorEnabled());
    $this->assertNull($user->two_factor_secret);
});
