<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PackageController;
use App\Http\Controllers\Api\V1\SslController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['throttle:60,1', 'api.auth', 'api.idempotency'])->group(function () {
    Route::get('/health', HealthController::class)->name('api.v1.health');
    Route::get('/packages', [PackageController::class, 'index'])->name('api.v1.packages.index');

    // Accounts
    Route::post('/accounts', [AccountController::class, 'store'])->name('api.v1.accounts.store');
    Route::get('/accounts/{id}', [AccountController::class, 'show'])->name('api.v1.accounts.show');
    Route::post('/accounts/{id}/suspend', [AccountController::class, 'suspend'])->name('api.v1.accounts.suspend');
    Route::post('/accounts/{id}/unsuspend', [AccountController::class, 'unsuspend'])->name('api.v1.accounts.unsuspend');
    Route::post('/accounts/{id}/upgrade', [AccountController::class, 'upgrade'])->name('api.v1.accounts.upgrade');
    Route::delete('/accounts/{id}', [AccountController::class, 'destroy'])->name('api.v1.accounts.destroy');
    Route::get('/accounts/{id}/usage', [AccountController::class, 'usage'])->name('api.v1.accounts.usage');
    Route::post('/accounts/{id}/password', [AccountController::class, 'password'])->name('api.v1.accounts.password');
    Route::post('/accounts/{id}/sso', [AccountController::class, 'sso'])->name('api.v1.accounts.sso');

    // Domains
    Route::post('/domains', [DomainController::class, 'store'])->name('api.v1.domains.store');

    // SSL
    Route::post('/ssl/issue', [SslController::class, 'issue'])->name('api.v1.ssl.issue');
});
