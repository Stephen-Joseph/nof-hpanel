<?php

use App\Http\Controllers\Api\GitWebhookController;
use App\Http\Controllers\Api\ServerEnrollmentController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PackageController;
use App\Http\Controllers\Api\V1\SslController;
use Illuminate\Support\Facades\Route;

// Public Webhooks & Server Node Enrollment (Rate Limited)
Route::prefix('v1')->group(function () {
    Route::post('/git-deploy/{secret}', GitWebhookController::class)
        ->middleware('throttle:30,1')
        ->name('api.v1.git-deploy');

    Route::post('/servers/enroll', ServerEnrollmentController::class)
        ->middleware('throttle:5,1')
        ->name('api.v1.servers.enroll');
});

Route::prefix('v1')->middleware(['throttle:60,1', 'api.auth', 'api.idempotency'])->group(function () {
    Route::get('/health', HealthController::class)->middleware('api.auth:health:read')->name('api.v1.health');
    Route::get('/packages', [PackageController::class, 'index'])->middleware('api.auth:packages:read')->name('api.v1.packages.index');

    // Accounts
    Route::post('/accounts', [AccountController::class, 'store'])->middleware('api.auth:accounts:write')->name('api.v1.accounts.store');
    Route::get('/accounts/{id}', [AccountController::class, 'show'])->middleware('api.auth:accounts:read')->name('api.v1.accounts.show');
    Route::post('/accounts/{id}/suspend', [AccountController::class, 'suspend'])->middleware('api.auth:accounts:write')->name('api.v1.accounts.suspend');
    Route::post('/accounts/{id}/unsuspend', [AccountController::class, 'unsuspend'])->middleware('api.auth:accounts:write')->name('api.v1.accounts.unsuspend');
    Route::post('/accounts/{id}/upgrade', [AccountController::class, 'upgrade'])->middleware('api.auth:accounts:write')->name('api.v1.accounts.upgrade');
    Route::delete('/accounts/{id}', [AccountController::class, 'destroy'])->middleware('api.auth:accounts:write')->name('api.v1.accounts.destroy');
    Route::get('/accounts/{id}/usage', [AccountController::class, 'usage'])->middleware('api.auth:usage:read|accounts:read')->name('api.v1.accounts.usage');
    Route::post('/accounts/{id}/password', [AccountController::class, 'password'])->middleware('api.auth:accounts:write')->name('api.v1.accounts.password');
    Route::post('/accounts/{id}/sso', [AccountController::class, 'sso'])->middleware('api.auth:accounts:read')->name('api.v1.accounts.sso');

    // Domains
    Route::post('/domains', [DomainController::class, 'store'])->middleware('api.auth:domains:write')->name('api.v1.domains.store');

    // SSL
    Route::post('/ssl/issue', [SslController::class, 'issue'])->middleware('api.auth:ssl:write')->name('api.v1.ssl.issue');
});
