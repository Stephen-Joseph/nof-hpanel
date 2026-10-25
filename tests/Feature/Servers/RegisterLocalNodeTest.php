<?php

use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Support\Facades\Artisan;

test('register-local-node running twice creates one row not two', function () {
    expect(Server::count())->toBe(0);

    // First execution
    $exitCode1 = Artisan::call('hpanel:register-local-node', [
        '--name' => 'Local VPS Node',
        '--public-ip' => '203.0.113.10',
        '--agent-url' => 'https://127.0.0.1:8443',
        '--private-ip' => '127.0.0.1',
        '--hostname' => 'vps1.example.com',
    ]);
    expect($exitCode1)->toBe(0);
    expect(Server::count())->toBe(1);

    /** @var Server $server1 */
    $server1 = Server::first();
    expect($server1->name)->toBe('Local VPS Node')
        ->and($server1->public_ip)->toBe('203.0.113.10')
        ->and($server1->agent_url)->toBe('https://127.0.0.1:8443')
        ->and($server1->private_ip)->toBe('127.0.0.1')
        ->and($server1->hostname)->toBe('vps1.example.com')
        ->and($server1->status)->toBe(ServerStatus::ACTIVE);

    // Second execution (re-run with updated public IP)
    $exitCode2 = Artisan::call('hpanel:register-local-node', [
        '--name' => 'Local VPS Node',
        '--public-ip' => '203.0.113.10,203.0.113.11',
        '--agent-url' => 'https://127.0.0.1:8443',
        '--private-ip' => '127.0.0.1',
        '--hostname' => 'vps1.example.com',
    ]);
    expect($exitCode2)->toBe(0);
    expect(Server::count())->toBe(1);

    /** @var Server $server2 */
    $server2 = Server::first();
    expect($server2->id)->toBe($server1->id)
        ->and($server2->public_ip)->toBe('203.0.113.10,203.0.113.11')
        ->and($server2->status)->toBe(ServerStatus::ACTIVE);
});

test('register-local-node matches by agent_url or name', function () {
    // Initial server
    Artisan::call('hpanel:register-local-node', [
        '--name' => 'Initial Name',
        '--public-ip' => '198.51.100.5',
        '--agent-url' => 'https://127.0.0.1:8443',
        '--private-ip' => '127.0.0.1',
    ]);
    expect(Server::count())->toBe(1);

    // Matching by agent_url even when name is changed
    Artisan::call('hpanel:register-local-node', [
        '--name' => 'Updated Name',
        '--public-ip' => '198.51.100.5',
        '--agent-url' => 'https://127.0.0.1:8443',
        '--private-ip' => '127.0.0.1',
    ]);
    expect(Server::count())->toBe(1);
    expect(Server::first()->name)->toBe('Updated Name');

    // Matching by name even when agent_url changes
    Artisan::call('hpanel:register-local-node', [
        '--name' => 'Updated Name',
        '--public-ip' => '198.51.100.5',
        '--agent-url' => 'https://127.0.0.1:9443',
        '--private-ip' => '127.0.0.1',
    ]);
    expect(Server::count())->toBe(1);
    expect(Server::first()->agent_url)->toBe('https://127.0.0.1:9443');
});

test('register-local-node does not output hmac secrets', function () {
    Artisan::call('hpanel:register-local-node', [
        '--name' => 'Secret Test Node',
        '--public-ip' => '203.0.113.10',
        '--agent-url' => 'https://127.0.0.1:8443',
        '--private-ip' => '127.0.0.1',
    ]);

    $output = Artisan::output();
    expect($output)->toContain('Secret Test Node')
        ->and($output)->not->toContain('secret')
        ->and($output)->not->toContain('PANEL_AGENT_HMAC_SECRET')
        ->and($output)->not->toContain('private_key');
});

test('register-local-node fails when public-ip is missing', function () {
    $exitCode = Artisan::call('hpanel:register-local-node', [
        '--name' => 'No Public IP Node',
        '--agent-url' => 'https://127.0.0.1:8443',
        '--private-ip' => '127.0.0.1',
    ]);

    expect($exitCode)->toBe(1);
    expect(Server::count())->toBe(0);
});

