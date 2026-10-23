<?php

use App\Enums\AccountStatus;
use App\Enums\ServerStatus;
use App\Jobs\SyncProtectedDirectoryJob;
use App\Jobs\WriteVhostJob;
use App\Livewire\Domains\ProtectedDirectoryManager;
use App\Models\Account;
use App\Models\Domain;
use App\Models\Package;
use App\Models\ProtectedDirectory;
use App\Models\Server;
use App\Models\User;
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

test('customer can view protected directories page', function () {
    $this->actingAs($this->customer)
        ->get(route('accounts.protected-dirs', $this->account))
        ->assertOk()
        ->assertSee('Protected Directories');
});

test('customer cannot view another accounts protected directories', function () {
    $this->actingAs($this->customer)
        ->get(route('accounts.protected-dirs', $this->otherAccount))
        ->assertForbidden();
});

test('customer can create a protected directory with hashed password', function () {
    Queue::fake();

    Livewire::actingAs($this->customer)
        ->test(ProtectedDirectoryManager::class, ['account' => $this->account])
        ->set('domain_id', $this->domain->id)
        ->set('path', '/admin')
        ->set('realm', 'Admin Area')
        ->set('new_username', 'adminuser')
        ->set('new_password', 'SuperSecurePass123!')
        ->call('createDirectory')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('protected_directories', [
        'domain_id' => $this->domain->id,
        'path' => '/admin',
        'realm' => 'Admin Area',
        'is_active' => true,
    ]);

    /** @var ProtectedDirectory $dir */
    $dir = ProtectedDirectory::where('domain_id', $this->domain->id)->firstOrFail();
    expect($dir->users)->toHaveCount(1)
        ->and($dir->users[0]['username'])->toBe('adminuser')
        ->and(password_verify('SuperSecurePass123!', $dir->users[0]['hash']))->toBeTrue();

    Queue::assertPushed(SyncProtectedDirectoryJob::class);
});

test('sync protected directory job executes htpasswd.write and reloads vhost', function () {
    Queue::fake([WriteVhostJob::class]);

    /** @var ProtectedDirectory $dir */
    $dir = $this->domain->protectedDirectories()->create([
        'path' => '/secret',
        'realm' => 'Secret Vault',
        'htpasswd_filename' => 'alicecorp.com_secret_123456.htpasswd',
        'users' => [
            ['username' => 'agent007', 'hash' => '$2y$10$hashed_test'],
        ],
        'is_active' => true,
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::any(),
            'htpasswd.write',
            Mockery::on(function ($payload) {
                return $payload['username'] === 'alicecorp'
                    && $payload['filename'] === 'alicecorp.com_secret_123456.htpasswd'
                    && count($payload['entries']) === 1;
            }),
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn([
            'success' => true,
            'data' => [
                'htpasswd_path' => '/home/alicecorp/.htpasswds/alicecorp.com_secret_123456.htpasswd',
                'user_count' => 1,
            ],
            'error' => null,
            'logs' => ['wrote htpasswd'],
            'request_id' => 'req-ht-1',
            'action' => 'htpasswd.write',
        ]);

    $job = new SyncProtectedDirectoryJob($dir);
    $job->handle($mockClient);

    Queue::assertPushed(WriteVhostJob::class);
});

test('customer can add additional users to protected directory', function () {
    Queue::fake();

    /** @var ProtectedDirectory $dir */
    $dir = $this->domain->protectedDirectories()->create([
        'path' => '/downloads',
        'realm' => 'Client Downloads',
        'htpasswd_filename' => 'alicecorp.com_downloads_123.htpasswd',
        'users' => [
            ['username' => 'client1', 'hash' => '$2y$10$hashed_one'],
        ],
        'is_active' => true,
    ]);

    Livewire::actingAs($this->customer)
        ->test(ProtectedDirectoryManager::class, ['account' => $this->account])
        ->set('targetDirectoryId', $dir->id)
        ->set('extra_username', 'client2')
        ->set('extra_password', 'ClientPass123!')
        ->call('addUser')
        ->assertHasNoErrors();

    $dir->refresh();
    expect($dir->users)->toHaveCount(2)
        ->and($dir->users[1]['username'])->toBe('client2')
        ->and(password_verify('ClientPass123!', $dir->users[1]['hash']))->toBeTrue();

    Queue::assertPushed(SyncProtectedDirectoryJob::class);
});

test('customer can delete a protected directory', function () {
    Queue::fake([WriteVhostJob::class]);

    /** @var ProtectedDirectory $dir */
    $dir = $this->domain->protectedDirectories()->create([
        'path' => '/old-admin',
        'realm' => 'Old Admin',
        'htpasswd_filename' => 'alicecorp.com_old_admin_123.htpasswd',
        'users' => [
            ['username' => 'admin', 'hash' => '$2y$10$hashed'],
        ],
        'is_active' => true,
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::any(),
            'htpasswd.delete',
            Mockery::any(),
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn([
            'success' => true,
            'data' => ['deleted' => 'file'],
            'error' => null,
            'logs' => ['deleted file'],
            'request_id' => 'req-del-ht',
            'action' => 'htpasswd.delete',
        ]);
    app()->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer)
        ->test(ProtectedDirectoryManager::class, ['account' => $this->account])
        ->call('deleteDirectory', $dir->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('protected_directories', [
        'id' => $dir->id,
    ]);
});
