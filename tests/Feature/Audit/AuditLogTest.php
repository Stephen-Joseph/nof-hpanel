<?php

use App\Livewire\Admin\AuditLogViewer;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use Livewire\Livewire;

test('audit service logs action with actor, target, and ip', function () {
    $user = User::factory()->create();
    $service = app(AuditService::class);

    $log = $service->log(
        action: 'user.created',
        target: $user,
        oldValues: null,
        newValues: ['name' => $user->name, 'email' => $user->email],
        actor: $user
    );

    expect($log)->toBeInstanceOf(AuditLog::class)
        ->and($log->action)->toBe('user.created')
        ->and($log->user_id)->toBe($user->id)
        ->and($log->target_id)->toBe($user->id)
        ->and($log->target_type)->toBe(User::class)
        ->and($log->new_values['name'])->toBe($user->name);
});

test('audit service automatically redacts secrets and credentials', function () {
    $service = app(AuditService::class);

    $cleanData = $service->redact([
        'username' => 'testuser',
        'password' => 'super-secret-password',
        'password_confirmation' => 'super-secret-password',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'token' => 'random-api-token',
        'nested' => [
            'api_token' => 'nested-secret',
            'safe_field' => 'ok_value',
        ],
    ]);

    expect($cleanData['username'])->toBe('testuser')
        ->and($cleanData['password'])->toBe('[REDACTED]')
        ->and($cleanData['password_confirmation'])->toBe('[REDACTED]')
        ->and($cleanData['two_factor_secret'])->toBe('[REDACTED]')
        ->and($cleanData['token'])->toBe('[REDACTED]')
        ->and($cleanData['nested']['api_token'])->toBe('[REDACTED]')
        ->and($cleanData['nested']['safe_field'])->toBe('ok_value');
});

test('non-admin cannot access admin audit logs route', function () {
    $reseller = User::factory()->reseller()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($reseller)->get('/admin/audit-logs')->assertStatus(403);
    $this->actingAs($customer)->get('/admin/audit-logs')->assertStatus(403);

    auth()->logout();
    $this->get('/admin/audit-logs')->assertRedirect('/login');
});

test('admin can access audit logs page and filter via livewire', function () {
    $admin = User::factory()->admin()->create();

    AuditLog::factory()->create([
        'action' => 'user.created',
        'user_id' => $admin->id,
    ]);

    AuditLog::factory()->create([
        'action' => 'auth.login',
        'user_id' => $admin->id,
    ]);

    $this->actingAs($admin)->get('/admin/audit-logs')->assertStatus(200);

    Livewire::actingAs($admin)
        ->test(AuditLogViewer::class)
        ->set('action', 'user.created')
        ->assertSee('user.created')
        ->assertDontSee('auth.login');
});
