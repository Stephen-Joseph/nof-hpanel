<?php

use App\Enums\UserRole;
use App\Livewire\Reseller\ResellerDashboard;
use App\Models\Package;
use App\Models\ResellerProfile;
use App\Models\Server;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create();

    $this->resellerA = User::factory()->reseller()->create([
        'name' => 'Reseller Alpha',
        'email' => 'alpha@reseller.com',
    ]);

    $this->profileA = ResellerProfile::create([
        'user_id' => $this->resellerA->id,
        'max_accounts' => 5,
        'max_storage_mb' => 20480, // 20 GB
        'max_bandwidth_gb' => 200, // 200 GB
        'company_name' => 'Alpha Cloud Hosting',
        'custom_domain' => 'panel.alphahost.com',
        'support_email' => 'support@alphahost.com',
    ]);

    $this->resellerB = User::factory()->reseller()->create([
        'name' => 'Reseller Beta',
        'email' => 'beta@reseller.com',
    ]);

    $this->profileB = ResellerProfile::create([
        'user_id' => $this->resellerB->id,
        'max_accounts' => 10,
        'max_storage_mb' => 51200,
        'max_bandwidth_gb' => 500,
        'company_name' => 'Beta Hosting Solutions',
    ]);

    $this->customerA = User::factory()->customer()->create([
        'name' => 'Customer of Alpha',
        'email' => 'custA@example.com',
        'parent_id' => $this->resellerA->id,
    ]);

    $this->customerB = User::factory()->customer()->create([
        'name' => 'Customer of Beta',
        'email' => 'custB@example.com',
        'parent_id' => $this->resellerB->id,
    ]);

    $this->pkgA = Package::factory()->create([
        'name' => 'Alpha Shared Starter',
        'reseller_owner_id' => $this->resellerA->id,
    ]);

    $this->pkgB = Package::factory()->create([
        'name' => 'Beta Elite Tier',
        'reseller_owner_id' => $this->resellerB->id,
    ]);
});

test('reseller can access their reseller dashboard', function () {
    $this->actingAs($this->resellerA)
        ->get(route('reseller.index'))
        ->assertOk()
        ->assertSee('Reseller Control Center');
});

test('standard customer is forbidden from reseller portal', function () {
    $this->actingAs($this->customerA)
        ->get(route('reseller.index'))
        ->assertForbidden();
});

test('reseller can add a new customer scoped to their hierarchy', function () {
    Livewire::actingAs($this->resellerA)
        ->test(ResellerDashboard::class)
        ->set('customer_name', 'New Alice Customer')
        ->set('customer_email', 'alice_cust@newclient.com')
        ->set('customer_password', 'Password123!')
        ->call('addCustomer')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('users', [
        'name' => 'New Alice Customer',
        'email' => 'alice_cust@newclient.com',
        'role' => UserRole::CUSTOMER->value,
        'parent_id' => $this->resellerA->id,
    ]);
});

test('reseller cannot create customers beyond their allocated pool limit', function () {
    // Fill up quota: profile max_accounts is 5
    for ($i = 1; $i <= 5; $i++) {
        $c = User::factory()->customer()->create(['parent_id' => $this->resellerA->id]);
        $c->accounts()->create([
            'server_id' => $this->server->id,
            'package_id' => $this->pkgA->id,
            'linux_username' => 'cust'.$i,
            'primary_domain' => "cust{$i}.com",
        ]);
    }

    Livewire::actingAs($this->resellerA)
        ->test(ResellerDashboard::class)
        ->set('customer_name', 'Exceeded User')
        ->set('customer_email', 'exceeded@test.com')
        ->set('customer_password', 'Password123!')
        ->call('addCustomer')
        ->assertSee('Account creation pool limit reached');

    $this->assertDatabaseMissing('users', [
        'email' => 'exceeded@test.com',
    ]);
});

test('tenant isolation: reseller A cannot see or manipulate reseller B customers', function () {
    Livewire::actingAs($this->resellerA)
        ->test(ResellerDashboard::class)
        ->assertSee('Customer of Alpha')
        ->assertDontSee('Customer of Beta');

    // Attempting to toggle Reseller B's customer fails with 404
    Livewire::actingAs($this->resellerA)
        ->test(ResellerDashboard::class)
        ->call('toggleCustomerStatus', $this->customerB->id)
        ->assertStatus(404);
});

test('tenant isolation: reseller A only sees packages they own', function () {
    Livewire::actingAs($this->resellerA)
        ->test(ResellerDashboard::class)
        ->set('activeTab', 'packages')
        ->assertSee('Alpha Shared Starter')
        ->assertDontSee('Beta Elite Tier');
});

test('reseller can create custom package within resource pool bounds', function () {
    Livewire::actingAs($this->resellerA)
        ->test(ResellerDashboard::class)
        ->set('pkg_name', 'Alpha Micro Plan')
        ->set('pkg_storage_mb', 2048)
        ->set('pkg_inodes', 25000)
        ->set('pkg_bandwidth_gb', 20)
        ->set('pkg_max_websites', 1)
        ->set('pkg_max_databases', 1)
        ->set('pkg_php_workers', 2)
        ->call('createPackage')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('packages', [
        'name' => 'Alpha Micro Plan',
        'reseller_owner_id' => $this->resellerA->id,
        'storage_mb' => 2048,
    ]);
});

test('package creation is rejected if limits exceed reseller pool limit', function () {
    // max_storage_mb on profileA is 20480
    Livewire::actingAs($this->resellerA)
        ->test(ResellerDashboard::class)
        ->set('pkg_name', 'Excessive Plan')
        ->set('pkg_storage_mb', 999999) // exceeds 20480
        ->set('pkg_inodes', 25000)
        ->set('pkg_bandwidth_gb', 20)
        ->set('pkg_max_websites', 1)
        ->set('pkg_max_databases', 1)
        ->set('pkg_php_workers', 2)
        ->call('createPackage')
        ->assertHasErrors(['pkg_storage_mb']);
});

test('reseller can update white-label branding', function () {
    Livewire::actingAs($this->resellerA)
        ->test(ResellerDashboard::class)
        ->set('company_name', 'Renamed Alpha Cloud')
        ->set('custom_domain', 'cloud.alpharebranded.com')
        ->set('support_email', 'help@alpharebranded.com')
        ->call('saveBranding')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('reseller_profiles', [
        'user_id' => $this->resellerA->id,
        'company_name' => 'Renamed Alpha Cloud',
        'custom_domain' => 'cloud.alpharebranded.com',
        'support_email' => 'help@alpharebranded.com',
    ]);
});
