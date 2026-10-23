<?php

use App\Enums\ServerStatus;
use App\Livewire\Logs\LogViewer;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'status' => ServerStatus::ACTIVE,
        'hostname' => 'node1.example.com',
        'private_ip' => '10.0.0.10',
    ]);

    $this->package = Package::factory()->create();

    $this->customer1 = User::factory()->customer()->create();
    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
    ]);

    $this->customer2 = User::factory()->customer()->create();
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobinc',
        'primary_domain' => 'bobinc.com',
    ]);
});

test('customer can view their account logs page', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->andReturn([
            'success' => true,
            'data' => [
                'log_files' => [
                    ['name' => 'alicecorp.com.access.log', 'size_bytes' => 1024, 'modified_at' => now()->toIso8601String(), 'category' => 'access'],
                ],
                'lines' => ['[2026-10-23 10:00:00] GET / 200'],
            ],
            'logs' => [],
        ]);

    $this->app->instance(AgentClient::class, $mockClient);

    $this->actingAs($this->customer1)
        ->get(route('accounts.logs', $this->account1))
        ->assertOk()
        ->assertSee('Logs &amp; Diagnostics', false);
});

test('customer cannot view another accounts logs', function () {
    $this->actingAs($this->customer1)
        ->get(route('accounts.logs', $this->account2))
        ->assertForbidden();
});

test('livewire log viewer interacts with agent to fetch lines', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'log.list';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'log_files' => [
                    ['name' => 'php_error.log', 'size_bytes' => 2048, 'modified_at' => now()->toIso8601String(), 'category' => 'php'],
                ],
            ],
            'logs' => [],
        ]);

    $mockClient->shouldReceive('execute')
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'log.tail' && $payload['log_file'] === 'php_error.log';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'lines' => ['[23-Oct-2026 12:00:00 UTC] PHP Warning: Undefined variable $foo'],
                'file_size_bytes' => 2048,
            ],
            'logs' => [],
        ]);

    $this->app->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer1)
        ->test(LogViewer::class, ['account' => $this->account1])
        ->assertSee('php_error.log')
        ->assertSee('Undefined variable $foo');
});

test('customer can download log file', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'log.tail' && $payload['log_file'] === 'alicecorp.com.access.log';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'lines' => [
                    '192.168.1.1 - - [23/Oct/2026:10:00:00 +0000] "GET / HTTP/1.1" 200',
                    '192.168.1.2 - - [23/Oct/2026:10:01:00 +0000] "POST /login HTTP/1.1" 302',
                ],
            ],
            'logs' => [],
        ]);

    $this->app->instance(AgentClient::class, $mockClient);

    $response = $this->actingAs($this->customer1)
        ->get(route('accounts.logs.download', ['account' => $this->account1, 'logFile' => 'alicecorp.com.access.log']));

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="alicecorp.com.access.log"')
        ->assertSee('GET / HTTP/1.1');
});

test('log download blocks path traversal attempts', function () {
    $this->actingAs($this->customer1)
        ->get(route('accounts.logs.download', ['account' => $this->account1, 'logFile' => '../../../etc/passwd']))
        ->assertStatus(400);
});
