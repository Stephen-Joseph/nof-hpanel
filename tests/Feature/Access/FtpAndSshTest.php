<?php

use App\Enums\ServerStatus;
use App\Livewire\Access\FtpManager;
use App\Livewire\Access\SshKeyManager;
use App\Models\Account;
use App\Models\FtpAccount;
use App\Models\Package;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\User;
use App\Services\AgentClient;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create(['status' => ServerStatus::ACTIVE, 'private_ip' => '10.0.0.30']);
    $this->package = Package::factory()->create([
        'max_ftp_accounts' => 2,
        'ssh_enabled' => true,
    ]);

    $this->customer1 = User::factory()->customer()->create(['email' => 'alice@corp.com']);
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

test('customer can view FTP and SSH management pages', function () {
    $this->actingAs($this->customer1)
        ->get(route('accounts.ftp', $this->account1))
        ->assertOk()
        ->assertSee('FTP / SFTP Accounts &middot; alicecorp.com', false);

    $this->actingAs($this->customer1)
        ->get(route('accounts.ssh', $this->account1))
        ->assertOk()
        ->assertSee('SSH Public Keys &middot; alicecorp.com', false);
});

test('cross-tenant customer is forbidden from accessing another account access pages', function () {
    $this->actingAs($this->customer1)
        ->get(route('accounts.ftp', $this->account2))
        ->assertForbidden();

    $this->actingAs($this->customer1)
        ->get(route('accounts.ssh', $this->account2))
        ->assertForbidden();
});

test('customer can create SFTP sub-account restricted to subfolder', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'sftp.account_create' &&
                $payload['parent_user'] === 'alicecorp' &&
                $payload['subuser'] === 'alicecorp_developer' &&
                $payload['homedir'] === '/home/alicecorp/public_html/dev';
        })
        ->andReturn(['success' => true, 'data' => ['configured' => true], 'logs' => []]);

    app()->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer1)
        ->test(FtpManager::class, ['account' => $this->account1])
        ->set('subuser', 'developer')
        ->set('homedir', '/home/alicecorp/public_html/dev')
        ->set('password', 'SecretP@ssw0rd123')
        ->set('password_confirmation', 'SecretP@ssw0rd123')
        ->call('createFtpAccount')
        ->assertHasNoErrors()
        ->assertSee("SFTP sub-account 'alicecorp_developer' created successfully.");

    $this->assertDatabaseHas('ftp_accounts', [
        'account_id' => $this->account1->id,
        'username' => 'alicecorp_developer',
        'homedir' => '/home/alicecorp/public_html/dev',
        'is_active' => true,
    ]);
});

test('creating SFTP account rejects homedir path traversal attempts', function () {
    Livewire::actingAs($this->customer1)
        ->test(FtpManager::class, ['account' => $this->account1])
        ->set('subuser', 'hacker')
        ->set('homedir', '/home/alicecorp/../../etc')
        ->set('password', 'SecretP@ssw0rd123')
        ->set('password_confirmation', 'SecretP@ssw0rd123')
        ->call('createFtpAccount')
        ->assertHasErrors(['homedir']);

    // Outside home directory
    Livewire::actingAs($this->customer1)
        ->test(FtpManager::class, ['account' => $this->account1])
        ->set('subuser', 'hacker')
        ->set('homedir', '/var/www')
        ->set('password', 'SecretP@ssw0rd123')
        ->set('password_confirmation', 'SecretP@ssw0rd123')
        ->call('createFtpAccount')
        ->assertHasErrors(['homedir']);
});

test('creating SFTP account enforces package quota limits', function () {
    // Fill quota (max 2)
    FtpAccount::create([
        'account_id' => $this->account1->id,
        'username' => 'alicecorp_sub1',
        'homedir' => '/home/alicecorp/public_html',
        'password_hash' => 'hash',
    ]);
    FtpAccount::create([
        'account_id' => $this->account1->id,
        'username' => 'alicecorp_sub2',
        'homedir' => '/home/alicecorp/public_html',
        'password_hash' => 'hash',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(FtpManager::class, ['account' => $this->account1])
        ->set('subuser', 'sub3')
        ->set('homedir', '/home/alicecorp/public_html')
        ->set('password', 'SecretP@ssw0rd123')
        ->set('password_confirmation', 'SecretP@ssw0rd123')
        ->call('createFtpAccount')
        ->assertHasErrors(['subuser']);
});

test('customer can add and delete SSH public key', function () {
    $mockClient = Mockery::mock(AgentClient::class);

    $pubKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH/9m3K/nJ3zL22Lq+hR4jH4H5P6cWjT/8G7b4vF8r3P macbook';

    // Add key
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) use ($pubKey) {
            return $action === 'ssh.key_add' &&
                $payload['username'] === 'alicecorp' &&
                $payload['public_key'] === $pubKey &&
                $payload['ssh_enabled'] === true;
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'key_type' => 'ssh-ed25519',
                'fingerprint' => 'SHA256:7uGgE22gT...',
            ],
            'logs' => [],
        ]);

    // Remove key
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $a, $p) => $a === 'ssh.key_remove')
        ->andReturn(['success' => true, 'data' => ['removed' => true], 'logs' => []]);

    app()->instance(AgentClient::class, $mockClient);

    $component = Livewire::actingAs($this->customer1)
        ->test(SshKeyManager::class, ['account' => $this->account1])
        ->set('name', 'MacBook Work')
        ->set('publicKey', $pubKey)
        ->call('addKey')
        ->assertHasNoErrors()
        ->assertSee("SSH key 'MacBook Work' added successfully.");

    $sshKey = SshKey::where('account_id', $this->account1->id)->first();
    expect($sshKey)->not->toBeNull()
        ->and($sshKey->name)->toEqual('MacBook Work')
        ->and($sshKey->key_type)->toEqual('ssh-ed25519');

    // Delete key
    $component->call('deleteKey', $sshKey->id)
        ->assertSee("SSH key 'MacBook Work' removed.");

    $this->assertDatabaseMissing('ssh_keys', ['id' => $sshKey->id]);
});
