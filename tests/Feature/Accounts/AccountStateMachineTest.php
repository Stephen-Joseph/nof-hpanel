<?php

use App\Enums\AccountStatus;
use App\Models\Account;

test('valid state machine transitions succeed', function () {
    $account = Account::factory()->pending()->create();

    // Pending -> Active
    expect($account->canTransitionTo(AccountStatus::ACTIVE))->toBeTrue();
    $account->transitionTo(AccountStatus::ACTIVE);
    expect($account->status)->toBe(AccountStatus::ACTIVE);

    // Active -> Suspended
    expect($account->canTransitionTo(AccountStatus::SUSPENDED))->toBeTrue();
    $account->transitionTo(AccountStatus::SUSPENDED, 'Billing overdue');
    expect($account->status)->toBe(AccountStatus::SUSPENDED)
        ->and($account->suspended_reason)->toBe('Billing overdue');

    // Suspended -> Active
    expect($account->canTransitionTo(AccountStatus::ACTIVE))->toBeTrue();
    $account->transitionTo(AccountStatus::ACTIVE);
    expect($account->status)->toBe(AccountStatus::ACTIVE)
        ->and($account->suspended_reason)->toBeNull();

    // Active -> Terminating -> Terminated
    expect($account->canTransitionTo(AccountStatus::TERMINATING))->toBeTrue();
    $account->transitionTo(AccountStatus::TERMINATING);
    expect($account->status)->toBe(AccountStatus::TERMINATING);

    expect($account->canTransitionTo(AccountStatus::TERMINATED))->toBeTrue();
    $account->transitionTo(AccountStatus::TERMINATED);
    expect($account->status)->toBe(AccountStatus::TERMINATED);
});

test('failure and retry transitions succeed', function () {
    $account = Account::factory()->pending()->create();

    // Pending -> Failed
    expect($account->canTransitionTo(AccountStatus::FAILED))->toBeTrue();
    $account->transitionTo(AccountStatus::FAILED, 'Timeout connecting to agent node');
    expect($account->status)->toBe(AccountStatus::FAILED)
        ->and($account->suspended_reason)->toBe('Timeout connecting to agent node');

    // Failed -> Pending (Retry)
    expect($account->canTransitionTo(AccountStatus::PENDING))->toBeTrue();
    $account->transitionTo(AccountStatus::PENDING);
    expect($account->status)->toBe(AccountStatus::PENDING);
});

test('invalid transitions throw DomainException', function () {
    $account = Account::factory()->active()->create();

    // Active cannot transition directly to Pending
    expect($account->canTransitionTo(AccountStatus::PENDING))->toBeFalse();
    $account->transitionTo(AccountStatus::PENDING);
})->throws(DomainException::class, 'Illegal state transition');

test('terminated account cannot transition to any state', function () {
    $account = Account::factory()->create(['status' => AccountStatus::TERMINATED]);

    expect($account->canTransitionTo(AccountStatus::ACTIVE))->toBeFalse()
        ->and($account->canTransitionTo(AccountStatus::PENDING))->toBeFalse();

    $account->transitionTo(AccountStatus::ACTIVE);
})->throws(DomainException::class, 'Illegal state transition');
