<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->resellerA = User::factory()->reseller()->create();
    $this->resellerB = User::factory()->reseller()->create();

    $this->customerA = User::factory()->customer($this->resellerA)->create();
    $this->customerB = User::factory()->customer($this->resellerB)->create();

    $this->accountA = Account::factory()->create(['user_id' => $this->customerA->id]);
    $this->accountB = Account::factory()->create(['user_id' => $this->customerB->id]);
});

test('admin can view, create, update, and delete any account', function () {
    expect(Gate::forUser($this->admin)->allows('viewAny', Account::class))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $this->accountA))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $this->accountB))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('create', Account::class))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('update', $this->accountA))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('delete', $this->accountA))->toBeTrue();
});

test('reseller can only view, update, and delete accounts in their own subtree', function () {
    // Reseller A accessing account of Customer A (own customer)
    expect(Gate::forUser($this->resellerA)->allows('view', $this->accountA))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('create', Account::class))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('update', $this->accountA))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('delete', $this->accountA))->toBeTrue();

    // Reseller A attempting to access account of Customer B (different reseller)
    expect(Gate::forUser($this->resellerA)->allows('view', $this->accountB))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('update', $this->accountB))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('delete', $this->accountB))->toBeFalse();
});

test('customer can view only their own accounts', function () {
    // Customer A viewing own account
    expect(Gate::forUser($this->customerA)->allows('view', $this->accountA))->toBeTrue();

    // Customer A attempting to access Customer B account
    expect(Gate::forUser($this->customerA)->allows('view', $this->accountB))->toBeFalse();

    // Customer CANNOT create, update, or delete accounts
    expect(Gate::forUser($this->customerA)->allows('create', Account::class))->toBeFalse()
        ->and(Gate::forUser($this->customerA)->allows('update', $this->accountA))->toBeFalse()
        ->and(Gate::forUser($this->customerA)->allows('delete', $this->accountA))->toBeFalse();
});
