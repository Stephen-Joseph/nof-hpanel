<?php

use App\Enums\AccountStatus;
use App\Jobs\ProvisionAccount;
use App\Jobs\SuspendAccount;
use App\Jobs\TerminateAccount;
use App\Jobs\UnsuspendAccount;
use App\Jobs\UpgradeAccount;
use App\Livewire\Accounts\AccountDetail;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create();
    $this->server = Server::factory()->create();
    $this->package = Package::factory()->create();

    $this->account = Account::factory()->active()->create([
        'user_id' => $this->customer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'testuser99',
    ]);
});

test('admin can dispatch suspension via Livewire', function () {
    Queue::fake();

    Livewire::actingAs($this->admin)
        ->test(AccountDetail::class, ['account' => $this->account])
        ->set('suspendReason', 'Non-payment notice #102')
        ->call('suspendAccount')
        ->assertSee('Suspension job');

    Queue::assertPushed(SuspendAccount::class, function ($job) {
        return $job->account->id === $this->account->id
            && $job->reason === 'Non-payment notice #102';
    });
});

test('admin can dispatch unsuspension via Livewire', function () {
    Queue::fake();
    $this->account->update(['status' => AccountStatus::SUSPENDED]);

    Livewire::actingAs($this->admin)
        ->test(AccountDetail::class, ['account' => $this->account])
        ->call('unsuspendAccount')
        ->assertSee('Unsuspension job');

    Queue::assertPushed(UnsuspendAccount::class, function ($job) {
        return $job->account->id === $this->account->id;
    });
});

test('admin can retry provisioning on failed account', function () {
    Queue::fake();
    $this->account->update(['status' => AccountStatus::FAILED]);

    Livewire::actingAs($this->admin)
        ->test(AccountDetail::class, ['account' => $this->account])
        ->call('retryProvisioning')
        ->assertSee('Provisioning retry job');

    Queue::assertPushed(ProvisionAccount::class, function ($job) {
        return $job->account->id === $this->account->id;
    });

    $this->account->refresh();
    expect($this->account->status)->toBe(AccountStatus::PENDING);
});

test('admin can dispatch package upgrade via Livewire', function () {
    Queue::fake();
    $newPackage = Package::factory()->create(['name' => 'Elite Tier']);

    Livewire::actingAs($this->admin)
        ->test(AccountDetail::class, ['account' => $this->account])
        ->set('newPackageId', $newPackage->id)
        ->call('upgradePackage')
        ->assertSee('Upgrade job');

    Queue::assertPushed(UpgradeAccount::class, function ($job) use ($newPackage) {
        return $job->account->id === $this->account->id
            && $job->newPackage->id === $newPackage->id;
    });
});

test('admin must enter exact token to terminate account', function () {
    Queue::fake();

    // Invalid token
    Livewire::actingAs($this->admin)
        ->test(AccountDetail::class, ['account' => $this->account])
        ->set('terminateConfirmation', 'wrong-token')
        ->call('terminateAccount')
        ->assertHasErrors(['terminateConfirmation']);

    Queue::assertNotPushed(TerminateAccount::class);

    // Valid token
    Livewire::actingAs($this->admin)
        ->test(AccountDetail::class, ['account' => $this->account])
        ->set('terminateConfirmation', 'TERMINATE-testuser99')
        ->call('terminateAccount')
        ->assertHasNoErrors()
        ->assertSee('Termination job');

    Queue::assertPushed(TerminateAccount::class, function ($job) {
        return $job->account->id === $this->account->id;
    });
});
