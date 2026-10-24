<?php

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\WebhookSubscription;
use Livewire\Livewire;
use App\Livewire\ApiIntegration\ApiOverview;
use App\Livewire\ApiIntegration\TokenManager;
use App\Livewire\ApiIntegration\TokenCreate;
use App\Livewire\ApiIntegration\WebhookManager;
use App\Livewire\ApiIntegration\RequestLogViewer;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->customerA = User::factory()->customer()->create();
    $this->customerB = User::factory()->customer()->create();
});

test('unauthenticated user is redirected to login for api-integration pages', function () {
    $this->get('/api-integration')->assertRedirect('/login');
    $this->get('/api-integration/tokens')->assertRedirect('/login');
    $this->get('/api-integration/tokens/create')->assertRedirect('/login');
    $this->get('/api-integration/webhooks')->assertRedirect('/login');
    $this->get('/api-integration/logs')->assertRedirect('/login');
    $this->get('/api-integration/docs')->assertRedirect('/login');
});

test('each api-integration page renders 200 for admin and customer', function () {
    foreach ([$this->admin, $this->customerA] as $user) {
        $this->actingAs($user)->get(route('api.integration.overview'))->assertOk();
        $this->actingAs($user)->get(route('api.integration.tokens'))->assertOk();
        $this->actingAs($user)->get(route('api.integration.tokens.create'))->assertOk();
        $this->actingAs($user)->get(route('api.integration.webhooks'))->assertOk();
        $this->actingAs($user)->get(route('api.integration.logs'))->assertOk();
        $this->actingAs($user)->get(route('api.integration.docs'))->assertOk();
    }
});

test('token creation displays plain token once and stores only hash in DB', function () {
    $component = Livewire::actingAs($this->customerA, 'web')
        ->test(TokenCreate::class)
        ->set('name', 'Customer A Test Token')
        ->set('scopes', ['accounts:read', 'usage:read'])
        ->set('expiry', '90')
        ->call('createToken');

    $plainToken = $component->get('generatedPlainTextToken');
    expect($plainToken)->toStartWith('hp_');

    // DB record must NOT contain plain token, only hash
    $token = ApiToken::where('name', 'Customer A Test Token')->first();
    expect($token)->not->toBeNull();
    expect($token->token_hash)->toBe(hash('sha256', $plainToken));
    expect($token->token_hash)->not->toBe($plainToken);
    expect($token->prefix)->toBe(substr($plainToken, 0, 8));
    expect($token->user_id)->toBe($this->customerA->id);
    expect($token->scopes)->toEqual(['accounts:read', 'usage:read']);
});

test('customer cannot see another customer tokens', function () {
    $tokenA = ApiToken::createToken($this->customerA, 'Token Customer A');
    $tokenB = ApiToken::createToken($this->customerB, 'Token Customer B');

    // Customer A view
    Livewire::actingAs($this->customerA)
        ->test(TokenManager::class)
        ->assertSee('Token Customer A')
        ->assertDontSee('Token Customer B');

    // Admin sees all
    Livewire::actingAs($this->admin)
        ->test(TokenManager::class)
        ->assertSee('Token Customer A')
        ->assertSee('Token Customer B');
});

test('customer cannot see another customer webhooks', function () {
    $subA = WebhookSubscription::create([
        'user_id' => $this->customerA->id,
        'url' => 'https://cust-a.com/webhook',
        'secret' => 'sec_a_123',
        'events' => ['account.created'],
        'is_active' => true,
    ]);

    $subB = WebhookSubscription::create([
        'user_id' => $this->customerB->id,
        'url' => 'https://cust-b.com/webhook',
        'secret' => 'sec_b_123',
        'events' => ['account.created'],
        'is_active' => true,
    ]);

    // Customer A view
    Livewire::actingAs($this->customerA)
        ->test(WebhookManager::class)
        ->assertSee('https://cust-a.com/webhook')
        ->assertDontSee('https://cust-b.com/webhook');

    // Admin sees both
    Livewire::actingAs($this->admin)
        ->test(WebhookManager::class)
        ->assertSee('https://cust-a.com/webhook')
        ->assertSee('https://cust-b.com/webhook');
});

test('customer cannot see another customer request logs', function () {
    $logA = ApiRequestLog::create([
        'user_id' => $this->customerA->id,
        'method' => 'GET',
        'path' => '/api/v1/accounts/cust-a',
        'status_code' => 200,
        'created_at' => now(),
    ]);

    $logB = ApiRequestLog::create([
        'user_id' => $this->customerB->id,
        'method' => 'GET',
        'path' => '/api/v1/accounts/cust-b',
        'status_code' => 200,
        'created_at' => now(),
    ]);

    // Customer A
    Livewire::actingAs($this->customerA)
        ->test(RequestLogViewer::class)
        ->assertSee('/api/v1/accounts/cust-a')
        ->assertDontSee('/api/v1/accounts/cust-b');

    // Admin
    Livewire::actingAs($this->admin)
        ->test(RequestLogViewer::class)
        ->assertSee('/api/v1/accounts/cust-a')
        ->assertSee('/api/v1/accounts/cust-b');
});

test('webhook registration rejects non-https URLs', function () {
    Livewire::actingAs($this->customerA)
        ->test(WebhookManager::class)
        ->set('url', 'http://insecure.com/webhook')
        ->call('createEndpoint')
        ->assertHasErrors(['url']);
});

test('request log viewer can export CSV', function () {
    ApiRequestLog::create([
        'user_id' => $this->admin->id,
        'method' => 'GET',
        'path' => '/api/v1/health',
        'status_code' => 200,
        'created_at' => now(),
    ]);

    $response = Livewire::actingAs($this->admin)
        ->test(RequestLogViewer::class)
        ->call('exportCsv');

    expect($response->effects['download']['name'] ?? '')->toStartWith('api-telemetry-');
});

