<?php

use App\Enums\AccountStatus;
use App\Enums\AppProfile;
use App\Enums\ServerStatus;
use App\Jobs\InstallWordPressJob;
use App\Jobs\UninstallWordPressJob;
use App\Jobs\WriteVhostJob;
use App\Livewire\WordPress\WordPressManager;
use App\Models\Account;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Models\WordPressInstall;
use App\Services\AgentClient;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'status' => ServerStatus::ACTIVE,
        'hostname' => 'node1.example.com',
        'private_ip' => '10.0.0.10',
    ]);

    $this->package = Package::factory()->create();

    $this->customer = User::factory()->customer()->create();
    $this->account = Account::factory()->create([
        'user_id' => $this->customer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
        'status' => AccountStatus::ACTIVE,
    ]);

    $this->domain = Domain::factory()->create([
        'account_id' => $this->account->id,
        'domain' => 'alicecorp.com',
        'document_root' => '/home/alicecorp/public_html',
        'app_profile' => AppProfile::PHP_GENERIC,
    ]);

    $this->otherCustomer = User::factory()->customer()->create();
    $this->otherAccount = Account::factory()->create([
        'user_id' => $this->otherCustomer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobcorp',
        'primary_domain' => 'bobcorp.com',
    ]);
});

test('customer can view their wordpress manager page', function () {
    $this->actingAs($this->customer)
        ->get(route('accounts.wordpress', $this->account))
        ->assertOk()
        ->assertSee('WordPress Manager');
});

test('customer cannot view another accounts wordpress page', function () {
    $this->actingAs($this->customer)
        ->get(route('accounts.wordpress', $this->otherAccount))
        ->assertForbidden();
});

test('customer can dispatch wordpress installation with auto db provisioning', function () {
    Queue::fake();

    Livewire::actingAs($this->customer)
        ->test(WordPressManager::class, ['account' => $this->account])
        ->set('domain_id', $this->domain->id)
        ->set('install_path', '/home/alicecorp/public_html')
        ->set('site_title', "Alice's Tech Blog")
        ->set('admin_user', 'aliceadmin')
        ->set('admin_password', 'SuperSecretPass123!')
        ->set('admin_email', 'alice@alicecorp.com')
        ->set('db_name', 'alicecorp_wp1')
        ->set('db_user', 'alicecorp_wpu1')
        ->set('db_password', 'DbSecretPassword123!')
        ->set('auto_create_db', false)
        ->call('install')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('wordpress_installs', [
        'account_id' => $this->account->id,
        'domain_id' => $this->domain->id,
        'site_title' => "Alice's Tech Blog",
        'admin_user' => 'aliceadmin',
        'admin_email' => 'alice@alicecorp.com',
        'db_name' => 'alicecorp_wp1',
    ]);

    Queue::assertPushed(InstallWordPressJob::class);
});

test('install wordpress job calls agent and configures wordpress profile', function () {
    Queue::fake([WriteVhostJob::class]);

    /** @var WordPressInstall $install */
    $install = $this->account->wordPressInstalls()->create([
        'domain_id' => $this->domain->id,
        'install_path' => '/home/alicecorp/public_html',
        'site_title' => "Alice's Tech Blog",
        'admin_user' => 'aliceadmin',
        'admin_email' => 'alice@alicecorp.com',
        'db_name' => 'alicecorp_wp1',
        'db_user' => 'alicecorp_wpu1',
        'status' => 'installing',
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::any(),
            'wordpress.install',
            Mockery::on(function ($payload) {
                return $payload['username'] === 'alicecorp'
                    && $payload['domain'] === 'alicecorp.com'
                    && $payload['admin_user'] === 'aliceadmin';
            }),
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn([
            'success' => true,
            'data' => [
                'domain' => 'alicecorp.com',
                'install_path' => '/home/alicecorp/public_html',
                'admin_user' => 'aliceadmin',
            ],
            'error' => null,
            'logs' => ['core downloaded', 'wp-config configured', 'installed'],
            'request_id' => 'req-wp-1',
            'action' => 'wordpress.install',
        ]);

    $job = new InstallWordPressJob($install, 'AdminPass123!', 'DbPass123!');
    $job->handle($mockClient);

    $install->refresh();
    expect($install->status)->toBe('installed');

    $this->domain->refresh();
    expect($this->domain->app_profile)->toBe(AppProfile::WORDPRESS);

    Queue::assertPushed(WriteVhostJob::class);
});

test('uninstall wordpress job deletes install record and switches profile', function () {
    Queue::fake([WriteVhostJob::class]);

    $this->domain->update(['app_profile' => AppProfile::WORDPRESS]);

    /** @var WordPressInstall $install */
    $install = $this->account->wordPressInstalls()->create([
        'domain_id' => $this->domain->id,
        'install_path' => '/home/alicecorp/public_html',
        'site_title' => 'To Delete',
        'admin_user' => 'aliceadmin',
        'admin_email' => 'alice@alicecorp.com',
        'db_name' => 'alicecorp_wp_del',
        'db_user' => 'alicecorp_wpu_del',
        'status' => 'installed',
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::any(),
            'wordpress.uninstall',
            Mockery::on(function ($payload) {
                return $payload['username'] === 'alicecorp'
                    && $payload['install_path'] === '/home/alicecorp/public_html';
            }),
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn([
            'success' => true,
            'data' => ['uninstalled' => true],
            'error' => null,
            'logs' => ['removed files'],
            'request_id' => 'req-wp-del',
            'action' => 'wordpress.uninstall',
        ]);

    $job = new UninstallWordPressJob($install, true);
    $job->handle($mockClient);

    $this->assertDatabaseMissing('wordpress_installs', [
        'id' => $install->id,
    ]);

    $this->domain->refresh();
    expect($this->domain->app_profile)->toBe(AppProfile::PHP_GENERIC);

    Queue::assertPushed(WriteVhostJob::class);
});
