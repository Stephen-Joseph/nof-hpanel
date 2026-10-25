<?php

use App\Models\Account;
use App\Models\Package;
use App\Models\ResellerProfile;
use App\Models\Server;
use App\Models\User;

beforeEach(function () {
    $this->server = Server::factory()->create();
    $this->package = Package::factory()->create();

    $this->admin = User::factory()->admin()->create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
    ]);

    $this->reseller = User::factory()->reseller()->create([
        'name' => 'Reseller User',
        'email' => 'reseller@example.com',
    ]);
    ResellerProfile::create([
        'user_id' => $this->reseller->id,
        'max_accounts' => 10,
        'max_storage_mb' => 10240,
        'max_bandwidth_gb' => 100,
        'company_name' => 'Reseller Corp',
    ]);

    $this->customer = User::factory()->customer()->create([
        'name' => 'Customer User',
        'email' => 'customer@example.com',
        'parent_id' => $this->reseller->id,
    ]);

    $this->account = Account::factory()->create([
        'user_id' => $this->customer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
    ]);
});

test('admin sees every admin link in sidebar', function () {
    $response = $this->actingAs($this->admin)->get(route('dashboard'));

    $response->assertOk();

    // Standard items
    $response->assertSee(route('dashboard'));
    $response->assertSee('Websites &amp; Domains', false);
    $response->assertSee(route('domains.index'));
    $response->assertSee(route('accounts.index'));

    // Admin & Reseller items
    $response->assertSee('Hosting Packages');
    $response->assertSee(route('packages.index'));

    // Admin exclusive items
    $response->assertSee('Server Nodes');
    $response->assertSee(route('servers.index'));
    $response->assertSee('Jobs');
    $response->assertSee(route('admin.jobs.index'));
    $response->assertSee('Usage');
    $response->assertSee(route('admin.usage.index'));
    $response->assertSee('API Tokens');
    $response->assertSee(route('admin.api-tokens.index'));
    $response->assertSee('Audit Trail');
    $response->assertSee(route('admin.audit-logs'));

    // API Integration & Footer items
    $response->assertSee('API Integration');
    $response->assertSee('Settings &amp; 2FA', false);
    $response->assertSee(route('profile.security'));
    $response->assertSee('Help &amp; Docs', false);
    $response->assertSee(route('api.integration.docs'));
    $response->assertSee('Sign Out');
    $response->assertSee(route('logout'));
});

test('customer does not see admin links in sidebar', function () {
    $response = $this->actingAs($this->customer)->get(route('dashboard'));

    $response->assertOk();

    // Customer sees own links
    $response->assertSee(route('dashboard'));
    $response->assertSee('Websites &amp; Domains', false);
    $response->assertSee(route('domains.index'));
    $response->assertSee('Accounts');
    $response->assertSee(route('accounts.index'));
    $response->assertSee('API Integration');
    $response->assertSee('Settings &amp; 2FA', false);
    $response->assertSee(route('profile.security'));
    $response->assertSee('Help &amp; Docs', false);
    $response->assertSee(route('api.integration.docs'));
    $response->assertSee('Sign Out');

    // Customer does NOT see admin links
    $response->assertDontSee(route('servers.index'));
    $response->assertDontSee(route('admin.jobs.index'));
    $response->assertDontSee(route('admin.usage.index'));
    $response->assertDontSee(route('admin.api-tokens.index'));
    $response->assertDontSee(route('admin.audit-logs'));
    $response->assertDontSee(route('packages.index'));
    $response->assertDontSee(route('reseller.index'));
});

test('reseller sees reseller links and cannot see admin-only links in sidebar', function () {
    $response = $this->actingAs($this->reseller)->get(route('dashboard'));

    $response->assertOk();

    // Reseller sees Dashboard, Reseller Center, Websites, Accounts, Packages, Usage
    $response->assertSee(route('dashboard'));
    $response->assertSee('Reseller Center');
    $response->assertSee(route('reseller.index'));
    $response->assertSee('Websites &amp; Domains', false);
    $response->assertSee(route('domains.index'));
    $response->assertSee('Accounts');
    $response->assertSee(route('accounts.index'));
    $response->assertSee('Hosting Packages');
    $response->assertSee(route('packages.index'));
    $response->assertSee('Usage');
    $response->assertSee(route('admin.usage.index'));
    $response->assertSee('API Integration');
    $response->assertSee('Settings &amp; 2FA', false);
    $response->assertSee('Help &amp; Docs', false);
    $response->assertSee('Sign Out');

    // Reseller does NOT see admin-exclusive links
    $response->assertDontSee(route('servers.index'));
    $response->assertDontSee(route('admin.jobs.index'));
    $response->assertDontSee(route('admin.api-tokens.index'));
    $response->assertDontSee(route('admin.audit-logs'));
});

test('admin can access all admin and management pages', function () {
    $this->actingAs($this->admin)->get(route('admin.jobs.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.usage.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.api-tokens.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.audit-logs'))->assertOk();
    $this->actingAs($this->admin)->get(route('servers.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('packages.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('reseller.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('accounts.index'))->assertOk();
});

test('reseller can access permitted pages but is forbidden from admin exclusive pages', function () {
    // Permitted for Reseller
    $this->actingAs($this->reseller)->get(route('admin.usage.index'))->assertOk();
    $this->actingAs($this->reseller)->get(route('reseller.index'))->assertOk();
    $this->actingAs($this->reseller)->get(route('packages.index'))->assertOk();
    $this->actingAs($this->reseller)->get(route('accounts.index'))->assertOk();

    // Forbidden for Reseller
    $this->actingAs($this->reseller)->get(route('admin.jobs.index'))->assertForbidden();
    $this->actingAs($this->reseller)->get(route('admin.api-tokens.index'))->assertForbidden();
    $this->actingAs($this->reseller)->get(route('admin.audit-logs'))->assertForbidden();
    $this->actingAs($this->reseller)->get(route('servers.index'))->assertForbidden();
});

test('customer is forbidden from admin and reseller pages', function () {
    $this->actingAs($this->customer)->get(route('admin.jobs.index'))->assertForbidden();
    $this->actingAs($this->customer)->get(route('admin.usage.index'))->assertForbidden();
    $this->actingAs($this->customer)->get(route('admin.api-tokens.index'))->assertForbidden();
    $this->actingAs($this->customer)->get(route('admin.audit-logs'))->assertForbidden();
    $this->actingAs($this->customer)->get(route('servers.index'))->assertForbidden();
    $this->actingAs($this->customer)->get(route('reseller.index'))->assertForbidden();

    // Permitted for Customer
    $this->actingAs($this->customer)->get(route('accounts.index'))->assertOk();
    $this->actingAs($this->customer)->get(route('domains.index'))->assertOk();
    $this->actingAs($this->customer)->get(route('api.integration.overview'))->assertOk();
    $this->actingAs($this->customer)->get(route('api.integration.tokens'))->assertOk();
});

test('job viewer displays related account link and handles search by account', function () {
    $job = \App\Models\AgentJob::factory()->create([
        'server_id' => $this->server->id,
        'account_id' => $this->account->id,
        'action' => 'account.create',
        'status' => \App\Enums\AgentJobStatus::COMPLETED,
    ]);

    \Livewire\Livewire::actingAs($this->admin)
        ->test(\App\Livewire\Admin\JobViewer::class)
        ->assertSee($this->account->primary_domain)
        ->assertSee($this->account->linux_username)
        ->assertSee(route('accounts.show', $this->account))
        ->set('search', $this->account->primary_domain)
        ->assertSee('account.create');
});

test('usage overview scopes accounts for reseller and renders threshold warning chips', function () {
    // Unrelated customer and account
    $otherCustomer = User::factory()->customer()->create(['name' => 'Other Customer']);
    $otherAccount = Account::factory()->create([
        'user_id' => $otherCustomer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'primary_domain' => 'unrelated-site.com',
    ]);

    // Reseller should see their customer's account but NOT the unrelated account
    \Livewire\Livewire::actingAs($this->reseller)
        ->test(\App\Livewire\Admin\UsageOverview::class)
        ->assertSee($this->account->primary_domain)
        ->assertDontSee('unrelated-site.com');

    // Test warning chips
    $this->package->update(['storage_mb' => 1000]);
    $this->account->update(['current_storage_mb' => 850]); // 85%

    \Livewire\Livewire::actingAs($this->admin)
        ->test(\App\Livewire\Admin\UsageOverview::class)
        ->assertSee('Warning (80%+)');

    $this->account->update(['current_storage_mb' => 950]); // 95%
    \Livewire\Livewire::actingAs($this->admin)
        ->test(\App\Livewire\Admin\UsageOverview::class)
        ->assertSee('Critical (90%+)');

    $this->account->update(['current_storage_mb' => 1050]); // 105%
    \Livewire\Livewire::actingAs($this->admin)
        ->test(\App\Livewire\Admin\UsageOverview::class)
        ->assertSee('Exceeded (100%+)');
});

test('api token manager displays owner and revokes via confirmation modal', function () {
    $token = \App\Models\ApiToken::create([
        'user_id' => $this->admin->id,
        'name' => 'Billing Automation Token',
        'token_hash' => hash('sha256', 'plain_test_token_secret_123'),
        'scopes' => ['accounts:read', 'accounts:write'],
    ]);

    \Livewire\Livewire::actingAs($this->admin)
        ->test(\App\Livewire\Admin\ApiTokenManager::class)
        ->assertSee('Billing Automation Token')
        ->assertSee($this->admin->name)
        ->call('confirmRevoke', $token->id)
        ->assertSet('tokenToRevokeId', $token->id)
        ->assertDispatched('open-modal', 'revoke-admin-token-modal')
        ->call('revokeToken')
        ->assertDispatched('close-modal', 'revoke-admin-token-modal')
        ->assertSee('revoked');

    $token->refresh();
    expect($token->isRevoked())->toBeTrue();
});

test('reseller dashboard shows active customers stat tile and recent accounts table', function () {
    \Livewire\Livewire::actingAs($this->reseller)
        ->test(\App\Livewire\Reseller\ResellerDashboard::class)
        ->assertSee('Customers')
        ->assertSee('Account Quota')
        ->assertSee('Storage Pool')
        ->assertSee('Recent Hosting Accounts')
        ->assertSee($this->account->primary_domain)
        ->assertSee($this->customer->name);
});

