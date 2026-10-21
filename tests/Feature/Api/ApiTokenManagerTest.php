<?php

use App\Livewire\Admin\ApiTokenManager;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\WebhookSubscription;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create();
});

test('non-admins cannot access API token manager page', function () {
    $this->actingAs($this->customer)->get('/admin/api-tokens')->assertStatus(403);
});

test('admin can access API token manager page', function () {
    $this->actingAs($this->admin)->get('/admin/api-tokens')->assertStatus(200);
});

test('admin can create and revoke API tokens via Livewire', function () {
    Livewire::actingAs($this->admin)
        ->test(ApiTokenManager::class)
        ->set('tokenName', 'WHMCS Billing Integration')
        ->set('selectedScopes', 'accounts:read,accounts:write')
        ->set('ipAllowlist', '192.168.1.100')
        ->set('expiresInDays', 30)
        ->call('createToken')
        ->assertSet('tokenName', '')
        ->assertSee('API token created successfully');

    $token = ApiToken::where('name', 'WHMCS Billing Integration')->firstOrFail();
    expect($token->scopes)->toBe(['accounts:read', 'accounts:write'])
        ->and($token->ip_allowlist)->toBe(['192.168.1.100'])
        ->and($token->isRevoked())->toBeFalse();

    // Revoke token
    Livewire::actingAs($this->admin)
        ->test(ApiTokenManager::class)
        ->call('revokeToken', $token->id)
        ->assertSee("API token '{$token->name}' revoked.");

    $token->refresh();
    expect($token->isRevoked())->toBeTrue();
});

test('admin can create and toggle webhook subscriptions', function () {
    Livewire::actingAs($this->admin)
        ->test(ApiTokenManager::class)
        ->set('webhookUrl', 'https://billing.example.org/hook')
        ->set('webhookEvents', 'account.created,account.suspended')
        ->call('createWebhook')
        ->assertSee('Webhook subscription registered.');

    $webhook = WebhookSubscription::where('url', 'https://billing.example.org/hook')->firstOrFail();
    expect($webhook->is_active)->toBeTrue()
        ->and($webhook->events)->toBe(['account.created', 'account.suspended']);

    // Toggle active
    Livewire::actingAs($this->admin)
        ->test(ApiTokenManager::class)
        ->call('toggleWebhook', $webhook->id);

    $webhook->refresh();
    expect($webhook->is_active)->toBeFalse();
});
