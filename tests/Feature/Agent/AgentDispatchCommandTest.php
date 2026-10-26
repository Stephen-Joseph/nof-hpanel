<?php

use App\Models\Server;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.agent.secret', 'test-secret-key-12345');

    $this->server = Server::factory()->create([
        'name' => 'Test Node',
        'hostname' => '127.0.0.1',
        'agent_url' => 'https://127.0.0.1:8443',
    ]);
});

test('agent dispatch executes action and returns success', function () {
    Http::fake([
        'https://127.0.0.1:8443/action' => Http::response([
            'request_id' => 'req-dispatch-1',
            'action' => 'system.ping',
            'success' => true,
            'data' => ['pong' => true],
        ], 200),
    ]);

    $this->artisan('hpanel:agent-dispatch', [
        'action' => 'system.ping',
        '--payload' => '{}',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('pong');
});

test('agent dispatch handles failure response', function () {
    Http::fake([
        'https://127.0.0.1:8443/action' => Http::response([
            'request_id' => 'req-dispatch-2',
            'action' => 'account.create',
            'success' => false,
            'error' => 'user already exists',
        ], 422),
    ]);

    $this->artisan('hpanel:agent-dispatch', [
        'action' => 'account.create',
        '--payload' => '{"username":"existing"}',
    ])
        ->assertFailed()
        ->expectsOutputToContain('Agent action failed');
});

test('agent dispatch rejects invalid json payload', function () {
    $this->artisan('hpanel:agent-dispatch', [
        'action' => 'system.ping',
        '--payload' => 'not-json',
    ])
        ->assertFailed()
        ->expectsOutputToContain('Invalid JSON payload provided');
});

