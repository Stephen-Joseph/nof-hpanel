<?php

use App\Enums\DomainType;
use App\Enums\ServerStatus;
use App\Jobs\SyncHtaccessRulesJob;
use App\Livewire\Domains\DomainList;
use App\Models\Account;
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
        'private_ip' => '10.0.0.30',
    ]);

    $this->package = Package::factory()->create();

    $this->customer1 = User::factory()->customer()->create();
    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'cust1user',
        'primary_domain' => 'my-apache-site.com',
    ]);
    $this->domain1 = Domain::factory()->create([
        'account_id' => $this->account1->id,
        'domain' => 'my-apache-site.com',
        'type' => DomainType::PRIMARY,
        'document_root' => '/home/cust1user/public_html',
        'htaccess_report' => [
            'exists' => true,
            'converted_count' => 3,
            'unsupported_count' => 1,
            'warning_count' => 0,
            'rules' => [
                [
                    'line' => 2,
                    'directive' => 'RewriteRule ^blog/(.*)$ /posts/$1 [L,R=301]',
                    'status' => 'converted',
                    'message' => 'Converted to 301 permanent redirect.',
                ],
                [
                    'line' => 5,
                    'directive' => 'ErrorDocument 404 /404.html',
                    'status' => 'converted',
                    'message' => 'Mapped HTTP 404 to custom document /404.html.',
                ],
                [
                    'line' => 7,
                    'directive' => 'DirectoryIndex index.php index.html',
                    'status' => 'converted',
                    'message' => 'Configured directory index.',
                ],
                [
                    'line' => 10,
                    'directive' => 'php_value upload_max_filesize 64M',
                    'status' => 'unsupported',
                    'message' => 'PHP settings are set in the panel\'s PHP Manager instead.',
                    'feature_link' => 'php',
                ],
            ],
            'synced_at' => now()->toIso8601String(),
        ],
    ]);

    $this->customer2 = User::factory()->customer()->create();
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'cust2user',
        'primary_domain' => 'other-site.com',
    ]);
    $this->domain2 = Domain::factory()->create([
        'account_id' => $this->account2->id,
        'domain' => 'other-site.com',
        'type' => DomainType::PRIMARY,
        'document_root' => '/home/cust2user/public_html',
    ]);
});

test('the .htaccess file is blocked from being served directly by the web server vhost', function () {
    // Assert that the Nginx virtual host configuration blocks dotfiles including .htaccess
    $vhostData = new \App\Jobs\WriteVhostJob($this->domain1);
    
    // Virtual host template contains deny all for dotfiles
    $template = file_get_contents(base_path('agent/internal/actions/vhost_templates.go'));
    expect($template)->toContain('location ~ /\.(?!well-known).* {')
        ->toContain('deny all;');
});

test('converter output appears on the Domains page', function () {
    Livewire::actingAs($this->customer1)
        ->test(DomainList::class)
        ->assertSee('my-apache-site.com')
        ->assertSee('.htaccess')
        ->call('openHtaccessModal', $this->domain1->id)
        ->assertSet('showingHtaccessModal', true)
        ->assertSet('viewingHtaccessDomainId', $this->domain1->id)
        ->assertSee('This server runs Nginx. Rules are converted automatically; unsupported rules are listed below.')
        ->assertSee('3 Converted')
        ->assertSee('1 Unsupported')
        ->assertSee('RewriteRule ^blog/(.*)$ /posts/$1 [L,R=301]')
        ->assertSee('php_value upload_max_filesize 64M')
        ->assertSee('PHP settings are set in the panel\'s PHP Manager instead.')
        ->assertSee('Open PHP Manager');
});

test('customer can only see their own domains in the .htaccess view', function () {
    // Customer 1 cannot see customer 2's domain in the list
    Livewire::actingAs($this->customer1)
        ->test(DomainList::class)
        ->assertSee('my-apache-site.com')
        ->assertDontSee('other-site.com');

    // Customer 1 cannot open modal for customer 2's domain
    Livewire::actingAs($this->customer1)
        ->test(DomainList::class)
        ->call('openHtaccessModal', $this->domain2->id)
        ->assertForbidden();
});

test('sync htaccess job dispatches agent action and updates domain report', function () {
    $this->mock(AgentClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('execute')
            ->withArgs(function ($server, $action, $payload) {
                return $action === 'htaccess.sync' &&
                    ($payload['domain'] ?? '') === 'my-apache-site.com' &&
                    ($payload['username'] ?? '') === 'cust1user';
            })
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [
                    'domain' => 'my-apache-site.com',
                    'exists' => true,
                    'converted_count' => 2,
                    'unsupported_count' => 0,
                    'warning_count' => 0,
                    'rules' => [
                        [
                            'line' => 1,
                            'directive' => 'DirectoryIndex index.php',
                            'status' => 'converted',
                            'message' => 'Configured directory index to index.php.',
                        ],
                    ],
                    'nginx_config' => "index index.php;\n",
                ],
            ]);
    });

    SyncHtaccessRulesJob::dispatchSync($this->domain1);

    $this->domain1->refresh();
    expect($this->domain1->htaccess_report)->not->toBeNull();
    expect($this->domain1->htaccess_report['converted_count'])->toBe(2);
    expect($this->domain1->htaccess_report['synced_at'])->not->toBeNull();
});
