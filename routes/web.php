<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DatabaseSsoController;
use App\Http\Controllers\LogDownloadController;
use App\Models\Account;
use App\Models\AgentJob;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use Illuminate\Support\Facades\Route;

// Guest Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/two-factor-challenge', [AuthenticatedSessionController::class, 'twoFactorChallenge'])->name('two-factor.login');
    Route::post('/two-factor-challenge', [AuthenticatedSessionController::class, 'twoFactorStore'])->name('two-factor.login.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLinkEmail'])->name('password.email');

    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
});

// Authenticated Routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Email Verification Routes
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Dashboard & Profile
    Route::get('/', function () {
        return view('dashboard');
    })->name('home');

    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/profile/security', function () {
        return view('profile.security');
    })->name('profile.security');

    // Admin Routes with optional IP Allowlist protection
    Route::middleware('admin.ip')->group(function () {
        Route::get('/admin/audit-logs', function () {
            return view('admin.audit-logs.index');
        })->middleware('can:viewAny,'.AuditLog::class)->name('admin.audit-logs');

        Route::get('/admin/jobs', function () {
            return view('admin.jobs.index');
        })->middleware('can:viewAny,'.AgentJob::class)->name('admin.jobs.index');

        Route::get('/admin/api-tokens', function () {
            return view('admin.api-tokens.index');
        })->middleware('can:viewAny,'.Server::class)->name('admin.api-tokens.index');

        Route::get('/admin/usage', function () {
            return view('admin.usage.index');
        })->middleware('can:create,'.Account::class)->name('admin.usage.index');

        // Servers (Admin Only)
        Route::get('/servers', function () {
            return view('servers.index');
        })->middleware('can:viewAny,'.Server::class)->name('servers.index');

        Route::get('/servers/create', function () {
            return view('servers.create');
        })->middleware('can:create,'.Server::class)->name('servers.create');

        Route::get('/servers/{server}/edit', function (Server $server) {
            return view('servers.edit', ['server' => $server]);
        })->middleware('can:update,server')->name('servers.edit');
    });

    // Reseller Control Center & Customer Management
    Route::get('/reseller', function () {
        return view('reseller.index');
    })->middleware('can:create,'.Account::class)->name('reseller.index');

    Route::get('/users/create', function () {
        return redirect()->route('reseller.index');
    })->name('users.create');

    // Packages
    Route::get('/packages', function () {
        return view('packages.index');
    })->middleware('can:viewAny,'.Package::class)->name('packages.index');

    Route::get('/packages/create', function () {
        return view('packages.create');
    })->middleware('can:create,'.Package::class)->name('packages.create');

    Route::get('/packages/{package}/edit', function (Package $package) {
        return view('packages.edit', ['package' => $package]);
    })->middleware('can:update,package')->name('packages.edit');

    // Accounts
    Route::get('/accounts', function () {
        return view('accounts.index');
    })->middleware('can:viewAny,'.Account::class)->name('accounts.index');

    Route::get('/accounts/create', function () {
        return view('accounts.create');
    })->middleware('can:create,'.Account::class)->name('accounts.create');

    Route::get('/accounts/{account}', function (Account $account) {
        return view('accounts.show', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.show');

    Route::get('/accounts/{account}/files', function (Account $account) {
        return view('files.index', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.files');

    Route::get('/accounts/{account}/ftp', function (Account $account) {
        return view('access.ftp', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.ftp');

    Route::get('/accounts/{account}/ssh', function (Account $account) {
        return view('access.ssh', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.ssh');

    Route::get('/accounts/{account}/databases', function (Account $account) {
        return view('databases.index', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.databases');

    Route::get('/accounts/{account}/databases/{databaseUser}/sso', [DatabaseSsoController::class, 'launch'])
        ->middleware('can:view,account')->name('accounts.databases.sso');

    Route::get('/accounts/{account}/pma-sso', [DatabaseSsoController::class, 'launchPma'])
        ->middleware('can:view,account')->name('accounts.databases.pma_sso');

    Route::get('/pma-sso/{token}', [DatabaseSsoController::class, 'consume'])
        ->name('pma.sso');

    Route::get('/accounts/{account}/cron', function (Account $account) {
        return view('cron.index', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.cron');

    Route::get('/accounts/{account}/logs', function (Account $account) {
        return view('logs.index', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.logs');

    Route::get('/accounts/{account}/logs/{logFile}/download', [LogDownloadController::class, 'download'])
        ->where('logFile', '.*')
        ->middleware('can:view,account')->name('accounts.logs.download');

    Route::get('/accounts/{account}/backups', function (Account $account) {
        return view('backups.index', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.backups');

    Route::get('/accounts/{account}/git', function (Account $account) {
        return view('git.index', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.git');

    Route::get('/accounts/{account}/wordpress', function (Account $account) {
        return view('wordpress.index', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.wordpress');

    Route::get('/accounts/{account}/protected-dirs', function (Account $account) {
        return view('domains.protected-dirs', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.protected-dirs');

    Route::get('/accounts/{account}/usage', function (Account $account) {
        return view('accounts.usage', ['account' => $account]);
    })->middleware('can:view,account')->name('accounts.usage');

    // Domains
    Route::get('/domains', function () {
        return view('domains.index');
    })->middleware('can:viewAny,'.Domain::class)->name('domains.index');

    Route::get('/domains/create', function () {
        return view('domains.create');
    })->middleware('can:create,'.Domain::class)->name('domains.create');

    Route::get('/domains/{domain}/redirects', function (Domain $domain) {
        return view('domains.redirects', ['domain' => $domain]);
    })->middleware('can:update,domain')->name('domains.redirects');

    Route::get('/domains/{domain}/dns', function (Domain $domain) {
        return view('domains.dns', ['domain' => $domain]);
    })->middleware('can:view,domain')->name('domains.dns');

    Route::get('/domains/{domain}/ssl', function (Domain $domain) {
        return view('domains.ssl', ['domain' => $domain]);
    })->middleware('can:view,domain')->name('domains.ssl');

    Route::get('/domains/{domain}/php', function (Domain $domain) {
        return view('domains.php', ['domain' => $domain]);
    })->middleware('can:view,domain')->name('domains.php');

    // API Integration
    Route::prefix('api-integration')->name('api.integration.')->middleware('verified')->group(function () {
        Route::get('/', App\Livewire\ApiIntegration\ApiOverview::class)->name('overview');
        Route::get('/tokens', App\Livewire\ApiIntegration\TokenManager::class)->name('tokens');
        Route::get('/tokens/create', App\Livewire\ApiIntegration\TokenCreate::class)->name('tokens.create');
        Route::get('/webhooks', App\Livewire\ApiIntegration\WebhookManager::class)->name('webhooks');
        Route::get('/logs', App\Livewire\ApiIntegration\RequestLogViewer::class)->name('logs');
        Route::get('/docs', function () {
            return view('api-integration.docs');
        })->name('docs');
    });

    // OpenAPI Specification
    Route::get('/docs/openapi.yaml', function () {
        $path = base_path('docs/openapi.yaml');
        abort_unless(file_exists($path), 404);

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'application/yaml',
        ]);
    })->name('docs.openapi');
});

// UI Kit Demo Route (local & testing environments only)
Route::get('/ui-kit', function () {
    abort_unless(app()->environment('local', 'testing'), 404);

    return view('ui-kit');
})->name('ui-kit');

// phpMyAdmin SSO Internal Redeem & Logout
Route::post('/internal/pma/redeem', [DatabaseSsoController::class, 'redeem'])
    ->name('internal.pma.redeem');

Route::get('/pma/logout', [DatabaseSsoController::class, 'logout'])
    ->name('pma.logout');
