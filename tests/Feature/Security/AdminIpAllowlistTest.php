<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;

test('admin routes are accessible when no ip allowlist is configured', function () {
    Config::set('hpanel.admin_ip_allowlist', []);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.195'])
        ->get(route('admin.audit-logs'))
        ->assertOk();
});

test('admin routes reject unauthorized client ips with 403 when allowlist is set', function () {
    Config::set('hpanel.admin_ip_allowlist', ['198.51.100.10', '192.168.1.0/24']);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->get(route('admin.audit-logs'))
        ->assertForbidden();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'admin.ip_blocked',
        'ip_address' => '203.0.113.50',
    ]);
});

test('admin routes permit authorized client ips matching explicit ip or cidr subnet', function () {
    Config::set('hpanel.admin_ip_allowlist', ['198.51.100.10', '192.168.1.0/24']);

    $admin = User::factory()->admin()->create();

    // 1. Exact IP match
    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->get(route('admin.audit-logs'))
        ->assertOk();

    // 2. CIDR subnet match (192.168.1.42 is in 192.168.1.0/24)
    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '192.168.1.42'])
        ->get(route('admin.audit-logs'))
        ->assertOk();
});

