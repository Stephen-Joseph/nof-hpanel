<?php

use App\Enums\DomainType;
use App\Enums\ServerStatus;
use App\Livewire\Admin\WebServerConfigManager;
use App\Livewire\Domains\WebServerSettings;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'status' => ServerStatus::ACTIVE,
        'private_ip' => '10.0.0.20',
    ]);

    $this->package = Package::factory()->create();

    $this->admin = User::factory()->admin()->create();

    $this->customer1 = User::factory()->customer()->create();
    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'cust1user',
        'primary_domain' => 'cust1.com',
    ]);
    $this->domain1 = Domain::factory()->create([
        'account_id' => $this->account1->id,
        'domain' => 'cust1.com',
        'type' => DomainType::PRIMARY,
        'document_root' => '/home/cust1user/public_html',
    ]);

    $this->customer2 = User::factory()->customer()->create();
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'cust2user',
        'primary_domain' => 'cust2.com',
    ]);
    $this->domain2 = Domain::factory()->create([
        'account_id' => $this->account2->id,
        'domain' => 'cust2.com',
        'type' => DomainType::PRIMARY,
        'document_root' => '/home/cust2user/public_html',
    ]);
});

test('customer cannot access admin web server route', function () {
    $this->actingAs($this->customer1)
        ->get(route('admin.web-server.index'))
        ->assertForbidden();
});

test('customer cannot access another customer domain web server route', function () {
    $this->actingAs($this->customer1)
        ->get(route('domains.web-server', $this->domain2))
        ->assertForbidden();
});

test('customer can access own domain web server route', function () {
    $this->mock(AgentClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('execute')
            ->withArgs(function ($server, $action, $payload) {
                return $action === 'nginx.custom.read';
            })
            ->andReturn([
                'success' => true,
                'data' => [
                    'domain' => 'cust1.com',
                    'content' => "# Custom Nginx configuration\n",
                    'exists' => true,
                ],
            ]);
    });

    $this->actingAs($this->customer1)
        ->get(route('domains.web-server', $this->domain1))
        ->assertOk()
        ->assertSee('Web Server Settings')
        ->assertSee('cust1.com');
});

test('customer structured edit rejects redirect loops', function () {
    $this->mock(AgentClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('execute')
            ->withArgs(function ($server, $action, $payload) {
                return $action === 'nginx.custom.read';
            })
            ->andReturn([
                'success' => true,
                'data' => ['domain' => 'cust1.com', 'content' => '', 'exists' => false],
            ]);
    });

    Livewire::actingAs($this->customer1)
        ->test(WebServerSettings::class, ['domain' => $this->domain1])
        ->set('redirects', [
            ['source' => '/my-page', 'target' => 'https://cust1.com/my-page', 'code' => 301],
        ])
        ->call('saveSettings')
        ->assertHasErrors(['redirects.0.target']);
});

test('customer structured edit applies settings and writes audit log without config content', function () {
    $this->mock(AgentClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('execute')
            ->withArgs(function ($server, $action, $payload) {
                return $action === 'nginx.custom.read';
            })
            ->andReturn([
                'success' => true,
                'data' => ['domain' => 'cust1.com', 'content' => '', 'exists' => false],
            ]);

        $mock->shouldReceive('execute')
            ->withArgs(function ($server, $action, $payload) {
                return $action === 'nginx.custom.write';
            })
            ->once()
            ->andReturn([
                'success' => true,
                'data' => ['domain' => 'cust1.com', 'nginx_output' => 'syntax ok', 'reloaded' => true],
            ]);
    });

    Livewire::actingAs($this->customer1)
        ->test(WebServerSettings::class, ['domain' => $this->domain1])
        ->set('max_upload_body_size_mb', 64)
        ->set('cache_lifetime_static', '7d')
        ->set('clean_urls', true)
        ->set('force_https', true)
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->assertSee('Web server settings applied');

    // Assert audit log row was written
    $log = AuditLog::where('action', 'nginx.custom.write')
        ->where('target_id', (string) $this->domain1->id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($this->customer1->id);

    // CRITICAL: assert NO raw config content is stored in the audit log
    $loggedNewValues = json_encode($log->new_values);
    expect($loggedNewValues)->not->toContain('server {');
    expect($loggedNewValues)->not->toContain('client_max_body_size');
    expect($loggedNewValues)->toContain('cust1.com');
});

test('admin can access web server manager and edit configs', function () {
    $this->mock(AgentClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('execute')
            ->withArgs(function ($server, $action, $payload) {
                return $action === 'nginx.config.list';
            })
            ->andReturn([
                'success' => true,
                'data' => [
                    'files' => [
                        [
                            'name' => 'example.com.conf',
                            'enabled' => true,
                            'owner' => 'exampleuser',
                            'size' => 1024,
                            'mtime' => time(),
                            'mtime_human' => now()->toIso8601String(),
                        ],
                    ],
                ],
            ]);

        $mock->shouldReceive('execute')
            ->withArgs(function ($server, $action, $payload) {
                return $action === 'nginx.config.read' && ($payload['name'] ?? '') === 'example.com.conf';
            })
            ->andReturn([
                'success' => true,
                'data' => [
                    'name' => 'example.com.conf',
                    'content' => "server {\n    listen 80;\n}\n",
                    'size' => 30,
                    'enabled' => true,
                ],
            ]);

        $mock->shouldReceive('execute')
            ->withArgs(function ($server, $action, $payload) {
                return $action === 'nginx.config.write';
            })
            ->once()
            ->andReturn([
                'success' => true,
                'data' => ['name' => 'example.com.conf', 'nginx_output' => 'syntax is ok', 'reloaded' => true],
            ]);
    });

    $this->actingAs($this->admin)
        ->get(route('admin.web-server.index'))
        ->assertOk()
        ->assertSee('Web Server Configuration');

    Livewire::actingAs($this->admin)
        ->test(WebServerConfigManager::class)
        ->call('openEditor', 'example.com.conf')
        ->assertSet('showingEditorModal', true)
        ->assertSet('activeEditingName', 'example.com.conf')
        ->set('editingContent', "server {\n    listen 80;\n    # updated\n}\n")
        ->call('initiateSave')
        ->assertSet('nginxTestSuccess', true)
        ->assertSee('saved and Nginx reloaded successfully');

    // Assert audit log row was created without raw content
    $log = AuditLog::where('action', 'nginx.config.write')->latest()->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($this->admin->id);
    expect(json_encode($log->new_values))->not->toContain('server {');
});

test('admin disabling panel own vhost requires typed confirmation', function () {
    $panelDomain = (string) config('hpanel.panel_domain');

    $this->mock(AgentClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('execute')
            ->withArgs(function ($server, $action, $payload) {
                return $action === 'nginx.config.list';
            })
            ->andReturn([
                'success' => true,
                'data' => [
                    'files' => [
                        [
                            'name' => config('hpanel.panel_domain') . '.conf',
                            'enabled' => true,
                            'owner' => 'system',
                            'size' => 2048,
                            'mtime' => time(),
                            'mtime_human' => now()->toIso8601String(),
                        ],
                    ],
                ],
            ]);
    });

    $component = Livewire::actingAs($this->admin)
        ->test(WebServerConfigManager::class)
        ->call('initiateDisable', $panelDomain . '.conf')
        ->assertSet('showingDisableTypedModal', true)
        ->assertSet('pendingDisableName', $panelDomain . '.conf');

    // Mismatched domain confirmation fails
    $component->set('typedConfirmDomain', 'wrong.domain.com')
        ->call('confirmTypedDisable')
        ->assertHasErrors(['typedConfirmDomain'])
        ->assertSet('showingDisableTypedModal', true);
});

