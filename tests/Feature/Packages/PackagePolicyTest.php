<?php

use App\Models\Account;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->resellerA = User::factory()->reseller()->create();
    $this->resellerB = User::factory()->reseller()->create();
    $this->customerA = User::factory()->customer($this->resellerA)->create();

    $this->systemPackage = Package::factory()->create(['reseller_owner_id' => null]);
    $this->packageA = Package::factory()->forReseller($this->resellerA)->create();
    $this->packageB = Package::factory()->forReseller($this->resellerB)->create();
});

test('admin has full control over all packages', function () {
    expect(Gate::forUser($this->admin)->allows('viewAny', Package::class))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $this->systemPackage))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $this->packageA))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('create', Package::class))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('update', $this->systemPackage))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('update', $this->packageA))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('delete', $this->systemPackage))->toBeTrue();
});

test('reseller can only manage their own packages and view system packages', function () {
    // Reseller can view system package and own package
    expect(Gate::forUser($this->resellerA)->allows('view', $this->systemPackage))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('view', $this->packageA))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('create', Package::class))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('update', $this->packageA))->toBeTrue()
        ->and(Gate::forUser($this->resellerA)->allows('delete', $this->packageA))->toBeTrue();

    // Reseller CANNOT update or delete system packages
    expect(Gate::forUser($this->resellerA)->allows('update', $this->systemPackage))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('delete', $this->systemPackage))->toBeFalse();

    // Reseller CANNOT view, update, or delete Reseller B's packages
    expect(Gate::forUser($this->resellerA)->allows('view', $this->packageB))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('update', $this->packageB))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('delete', $this->packageB))->toBeFalse();
});

test('package cannot be deleted if assigned to an account', function () {
    Account::factory()->create([
        'package_id' => $this->packageA->id,
    ]);

    expect(Gate::forUser($this->admin)->allows('delete', $this->packageA))->toBeFalse()
        ->and(Gate::forUser($this->resellerA)->allows('delete', $this->packageA))->toBeFalse();
});

test('customer cannot create, update, or delete any package', function () {
    expect(Gate::forUser($this->customerA)->allows('create', Package::class))->toBeFalse()
        ->and(Gate::forUser($this->customerA)->allows('update', $this->systemPackage))->toBeFalse()
        ->and(Gate::forUser($this->customerA)->allows('update', $this->packageA))->toBeFalse()
        ->and(Gate::forUser($this->customerA)->allows('delete', $this->packageA))->toBeFalse();
});
