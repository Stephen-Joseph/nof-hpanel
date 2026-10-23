<?php

use App\Models\Account;
use App\Models\Domain;
use App\Models\Package;
use App\Models\ResellerProfile;
use App\Models\Server;
use App\Models\User;

beforeEach(function () {
    $this->server = Server::factory()->create(['name' => 'Primary Node']);
    $this->package = Package::factory()->create([
        'name' => 'Starter Cloud',
        'storage_mb' => 10240,
        'bandwidth_gb' => 100,
        'inodes' => 100000,
        'max_websites' => 5,
        'max_databases' => 3,
    ]);
});

test('customer dashboard displays quick tiles with metrics and launchpad', function () {
    $customer = User::factory()->customer()->create(['name' => 'John Customer']);
    $account = Account::factory()->create([
        'user_id' => $customer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'johncust',
        'current_storage_mb' => 2048,
        'current_bandwidth_bytes' => 15360 * 1024 * 1024,
        'current_inodes' => 12500,
    ]);

    $domain = Domain::factory()->create([
        'account_id' => $account->id,
        'domain' => 'johnsite.com',
        'php_version' => '8.3',
    ]);

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome back, John Customer')
        ->assertSee('johncust')
        ->assertSee('Starter Cloud')
        ->assertSee('Disk Storage')
        ->assertSee('2.0 GB')
        ->assertSee('Monthly Traffic')
        ->assertSee('Files & Inodes')
        ->assertSee('12,500')
        ->assertSee('Websites')
        ->assertSee('Databases')
        ->assertSee('johnsite.com')
        ->assertSee('PHP 8.3');
});

test('admin dashboard displays fleet health, node count, and administrative stats', function () {
    $admin = User::factory()->admin()->create(['name' => 'System Admin']);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('System Administration')
        ->assertSee('Server Nodes')
        ->assertSee('Primary Node')
        ->assertSee('Total Accounts')
        ->assertSee('Audit Trail');
});

test('reseller dashboard displays resource pool utilization and branding', function () {
    $reseller = User::factory()->reseller()->create(['name' => 'HostCorp Reseller']);
    ResellerProfile::create([
        'user_id' => $reseller->id,
        'max_accounts' => 25,
        'max_storage_mb' => 51200,
        'max_bandwidth_gb' => 1000,
        'company_name' => 'HostCorp Cloud',
        'custom_domain' => 'reseller.hostcorp.com',
    ]);

    $this->actingAs($reseller)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Reseller Overview')
        ->assertSee('HostCorp Cloud')
        ->assertSee('Account Quota')
        ->assertSee('Storage Pool')
        ->assertSee('Bandwidth Pool')
        ->assertSee('Reseller Control Center');
});
