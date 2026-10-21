<?php

use App\Enums\AccountStatus;
use App\Enums\ServerStatus;
use App\Jobs\ProvisionAccount;
use App\Livewire\Accounts\AccountCreate;
use App\Livewire\Accounts\AccountDetail;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->server = Server::factory()->create(['status' => ServerStatus::ACTIVE]);
    $this->package = Package::factory()->create();
    $this->customer = User::factory()->customer()->create();
});

test('account creation creates pending record and dispatches provision job', function () {
    Queue::fake();

    Livewire::actingAs($this->admin)
        ->test(AccountCreate::class)
        ->set('user_id', $this->customer->id)
        ->set('server_id', $this->server->id)
        ->set('package_id', $this->package->id)
        ->set('linux_username', 'acctuser')
        ->set('primary_domain', 'myhosting.example.com')
        ->call('save')
        ->assertRedirect();

    $this->assertDatabaseHas('accounts', [
        'linux_username' => 'acctuser',
        'primary_domain' => 'myhosting.example.com',
        'status' => AccountStatus::PENDING->value,
    ]);

    Queue::assertPushed(ProvisionAccount::class, function ($job) {
        return $job->account->linux_username === 'acctuser';
    });

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'account.created',
        'user_id' => $this->admin->id,
    ]);
});

test('username must conform to strict linux username regex', function (string $invalidUsername) {
    Livewire::actingAs($this->admin)
        ->test(AccountCreate::class)
        ->set('user_id', $this->customer->id)
        ->set('server_id', $this->server->id)
        ->set('package_id', $this->package->id)
        ->set('linux_username', $invalidUsername)
        ->set('primary_domain', 'valid.com')
        ->call('save')
        ->assertHasErrors(['linux_username']);
})->with([
    '123user',     // Starts with digit
    'UserCaps',    // Uppercase
    'ab',          // Too short (< 3 chars)
    'toolongusernameexceedinglimit', // Too long (> 16 chars)
    'user-name',   // Contains hyphen
    'user_name',   // Contains underscore
    'user.name',   // Contains dot
    'root',        // while 4 letters, let's ensure regex validates lowercase alphanumeric
]);

test('primary domain must conform to rfc regex', function (string $invalidDomain) {
    Livewire::actingAs($this->admin)
        ->test(AccountCreate::class)
        ->set('user_id', $this->customer->id)
        ->set('server_id', $this->server->id)
        ->set('package_id', $this->package->id)
        ->set('linux_username', 'validuser')
        ->set('primary_domain', $invalidDomain)
        ->call('save')
        ->assertHasErrors(['primary_domain']);
})->with([
    'invalid domain with spaces',
    'http://domain.com',
    'https://domain.com',
    'notadomain',
    '.leadingdot.com',
]);

test('reseller cannot create account for another resellers customer', function () {
    $resellerA = User::factory()->reseller()->create();
    $resellerB = User::factory()->reseller()->create();
    $customerB = User::factory()->customer($resellerB)->create();

    Livewire::actingAs($resellerA)
        ->test(AccountCreate::class)
        ->set('user_id', $customerB->id)
        ->set('server_id', $this->server->id)
        ->set('package_id', $this->package->id)
        ->set('linux_username', 'intruder')
        ->set('primary_domain', 'intruder.com')
        ->call('save')
        ->assertStatus(403);
});

test('authorized user can suspend and unsuspend account via detail livewire', function () {
    Http::fake([
        '*' => Http::response([
            'success' => true,
            'data' => ['suspended' => true],
            'logs' => ['action executed'],
        ], 200),
    ]);

    $account = Account::factory()->active()->create([
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
    ]);

    Livewire::actingAs($this->admin)
        ->test(AccountDetail::class, ['account' => $account])
        ->set('suspendReason', 'Non-payment of invoice')
        ->call('suspendAccount')
        ->assertHasNoErrors();

    $account->refresh();
    expect($account->status)->toBe(AccountStatus::SUSPENDED)
        ->and($account->suspended_reason)->toBe('Non-payment of invoice');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'account.suspended',
        'target_id' => $account->id,
    ]);

    // Unsuspend
    Livewire::actingAs($this->admin)
        ->test(AccountDetail::class, ['account' => $account])
        ->call('unsuspendAccount')
        ->assertHasNoErrors();

    $account->refresh();
    expect($account->status)->toBe(AccountStatus::ACTIVE)
        ->and($account->suspended_reason)->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'account.unsuspended',
        'target_id' => $account->id,
    ]);
});
