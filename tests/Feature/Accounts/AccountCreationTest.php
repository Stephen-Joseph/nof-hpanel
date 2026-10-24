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

test('customer dropdown shows all customers for admin and placeholder option', function () {
    $customer2 = User::factory()->customer()->create([
        'name' => 'Bob Customer',
        'email' => 'bob@example.com',
    ]);

    Livewire::actingAs($this->admin)
        ->test(AccountCreate::class)
        ->assertSee('Select customer...')
        ->assertSee($this->customer->name)
        ->assertSee($this->customer->email)
        ->assertSee('Bob Customer')
        ->assertSee('bob@example.com');
});

test('empty state renders when no customers exist with link to user creation', function () {
    // Delete all customers
    User::where('role', \App\Enums\UserRole::CUSTOMER)->delete();

    Livewire::actingAs($this->admin)
        ->test(AccountCreate::class)
        ->assertSee('No customers yet. Create a customer first.')
        ->assertSee(route('users.create'));
});

test('customer dropdown scopes customers for reseller to their own subtree', function () {
    $reseller = User::factory()->reseller()->create();
    $resellerCustomer = User::factory()->customer()->create([
        'name' => 'Reseller Client',
        'email' => 'reseller_client@example.com',
        'parent_id' => $reseller->id,
    ]);
    $otherCustomer = User::factory()->customer()->create([
        'name' => 'Stranger Client',
        'email' => 'stranger@example.com',
    ]);

    Livewire::actingAs($reseller)
        ->test(AccountCreate::class)
        ->assertSee('Reseller Client')
        ->assertDontSee('Stranger Client');
});

test('wire:model retains selected customer after validation error', function () {
    Livewire::actingAs($this->admin)
        ->test(AccountCreate::class)
        ->set('user_id', $this->customer->id)
        ->set('linux_username', 'invalid username with spaces') // invalid to trigger error
        ->call('save')
        ->assertHasErrors(['linux_username'])
        ->assertSet('user_id', $this->customer->id);
});

test('full account creation flow creates row and flashes success message', function () {
    Queue::fake();

    $testComponent = Livewire::actingAs($this->admin)
        ->test(AccountCreate::class)
        ->set('user_id', $this->customer->id)
        ->set('server_id', $this->server->id)
        ->set('package_id', $this->package->id)
        ->set('linux_username', 'endtoend')
        ->set('primary_domain', 'endtoend.com')
        ->call('save');

    $account = Account::where('linux_username', 'endtoend')->first();
    expect($account)->not->toBeNull();
    expect($account->user_id)->toBe($this->customer->id);
    expect($account->server_id)->toBe($this->server->id);
    expect($account->package_id)->toBe($this->package->id);
    expect($account->primary_domain)->toBe('endtoend.com');

    $testComponent->assertRedirect(route('accounts.show', $account));
    $testComponent->assertSessionHas('status', "Account {$account->linux_username} successfully created and enqueued for provisioning.");
});

test('customer select forwards name and wire:model attributes and validates correctly on submit', function () {
    Queue::fake();

    $component = Livewire::actingAs($this->admin)
        ->test(AccountCreate::class);

    // Verify name="user_id" and wire:model.live="user_id" are rendered on select element
    $html = $component->html();
    expect($html)->toContain('name="user_id"')
        ->and($html)->toContain('id="user_id"')
        ->and($html)->toContain('wire:model.live="user_id"')
        ->and($html)->toContain('value="' . $this->customer->id . '"')
        ->and($html)->toContain('value="" disabled selected>Select customer...</option>');

    // Submitting without user_id must assert validation error for user_id
    $component->call('save')
        ->assertHasErrors(['user_id' => 'required']);

    // Submitting with valid user_id, server_id, and package_id creates account and has NO user_id error
    $component->set('user_id', $this->customer->id)
        ->set('server_id', $this->server->id)
        ->set('package_id', $this->package->id)
        ->set('linux_username', 'acctok')
        ->set('primary_domain', 'acctok.example.com')
        ->call('save')
        ->assertHasNoErrors(['user_id', 'server_id', 'package_id'])
        ->assertRedirect();

    $this->assertDatabaseHas('accounts', [
        'user_id' => $this->customer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'acctok',
        'primary_domain' => 'acctok.example.com',
    ]);
});
