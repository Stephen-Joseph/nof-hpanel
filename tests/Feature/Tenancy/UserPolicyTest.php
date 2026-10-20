<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->resellerA = User::factory()->reseller()->create();
    $this->resellerB = User::factory()->reseller()->create();

    $this->customerA = User::factory()->customer($this->resellerA)->create();
    $this->customerB = User::factory()->customer($this->resellerB)->create();
    $this->independentCustomer = User::factory()->customer()->create();
});

test('admin can access and manage any user across all tenants', function () {
    expect(Gate::forUser($this->admin)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $this->customerA))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $this->customerB))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $this->resellerA))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('create', User::class))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('update', $this->customerA))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('delete', $this->customerA))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('delete', $this->resellerA))->toBeTrue();
});

test('admin cannot delete own account', function () {
    expect(Gate::forUser($this->admin)->allows('delete', $this->admin))->toBeFalse();
});

test('reseller can view and manage only customers in their own subtree', function () {
    // Reseller A managing Customer A (own customer)
    expect(Gate::forUser($this->resellerA)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('view', $this->customerA))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('update', $this->customerA))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('delete', $this->customerA))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('create', User::class))->toBeTrue();

    // Reseller A attempting to access Customer B (Reseller B's customer)
    expect(Gate::forUser($this->resellerA)->allows('view', $this->customerB))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('update', $this->customerB))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('delete', $this->customerB))->toBeFalse();

    // Reseller A attempting to access Independent Customer
    expect(Gate::forUser($this->resellerA)->allows('view', $this->independentCustomer))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('update', $this->independentCustomer))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('delete', $this->independentCustomer))->toBeFalse();

    // Reseller A attempting to access Admin
    expect(Gate::forUser($this->resellerA)->allows('view', $this->admin))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('update', $this->admin))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('delete', $this->admin))->toBeFalse();

    // Reseller A cannot delete themselves
    expect(Gate::forUser($this->resellerA)->allows('delete', $this->resellerA))->toBeFalse();
});

test('nested reseller subtrees are recognized by ancestors', function () {
    // Sub-reseller owned by Reseller A
    $subReseller = User::factory()->reseller()->create([
        'parent_id' => $this->resellerA->id,
    ]);

    // Sub-customer owned by Sub-reseller
    $subCustomer = User::factory()->customer($subReseller)->create();

    // Reseller A should have access to sub-customer in their hierarchy
    expect(Gate::forUser($this->resellerA)->allows('view', $subCustomer))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('update', $subCustomer))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('delete', $subCustomer))->toBeTrue();

    // Reseller B should NOT have access
    expect(Gate::forUser($this->resellerB)->allows('view', $subCustomer))->toBeFalse()
        ->and(Gate::forUser($this->resellerB)->allows('update', $subCustomer))->toBeFalse()
        ->and(Gate::forUser($this->resellerB)->allows('delete', $subCustomer))->toBeFalse();
});

test('customer can only access and update their own account', function () {
    // Customer cannot view index
    expect(Gate::forUser($this->customerA)->allows('viewAny', User::class))->toBeFalse()
        ->and(Gate::forUser($this->customerA)->allows('create', User::class))->toBeFalse();

    // Customer can view and update self
    expect(Gate::forUser($this->customerA)->allows('view', $this->customerA))->toBeTrue()
        ->and(Gate::forUser($this->customerA)->allows('update', $this->customerA))->toBeTrue();

    // Customer CANNOT delete self
    expect(Gate::forUser($this->customerA)->allows('delete', $this->customerA))->toBeFalse();

    // Customer CANNOT view, update, or delete other customer
    expect(Gate::forUser($this->customerA)->allows('view', $this->customerB))->toBeFalse()
        ->and(Gate::forUser($this->customerA)->allows('update', $this->customerB))->toBeFalse()
        ->and(Gate::forUser($this->customerA)->allows('delete', $this->customerB))->toBeFalse();

    // Customer CANNOT view, update, or delete reseller or admin
    expect(Gate::forUser($this->customerA)->allows('view', $this->resellerA))->toBeFalse()
        ->and(Gate::forUser($this->customerA)->allows('update', $this->resellerA))->toBeFalse()
        ->and(Gate::forUser($this->customerA)->allows('view', $this->admin))->toBeFalse();
});
