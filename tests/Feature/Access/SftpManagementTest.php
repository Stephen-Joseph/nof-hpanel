<?php

use App\Enums\ServerStatus;
use App\Livewire\Access\FtpManager;
use App\Livewire\Servers\ServerList;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'name' => 'Web-Node-01',
        'status' => ServerStatus::ACTIVE,
        'private_ip' => '10.0.0.30',
        'public_ip' => '198.51.100.50',
        'hostname' => 'node1.example.com',
    ]);

    $this->package = Package::factory()->create([
        'max_ftp_accounts' => 5,
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

    $this->customer2 = User::factory()->customer()->create(['email' => 'bob@corp.com']);
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobinc',
        'primary_domain' => 'bobinc.com',
    ]);

    $this->admin = User::factory()->admin()->create(['email' => 'admin@panel.test']);
});

test('customer can set primary account SFTP password which calls sftp.enable and sftp.set_password', function () {
    $mockClient = Mockery::mock(AgentClient::class);

    // 1. Expect sftp.enable
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'sftp.enable' &&
                $server->id === $this->server->id &&
                $payload['username'] === 'alicecorp';
        })
        ->andReturn(['success' => true, 'data' => ['enabled' => true], 'logs' => []]);

    // 2. Expect sftp.set_password
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'sftp.set_password' &&
                $server->id === $this->server->id &&
                $payload['username'] === 'alicecorp' &&
                $payload['password'] === 'P@ssword123456';
        })
        ->andReturn(['success' => true, 'data' => ['updated' => true], 'logs' => []]);

    app()->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer1)
        ->test(FtpManager::class, ['account' => $this->account1])
        ->call('openPrimaryPasswordModal')
        ->assertSet('isSettingPrimaryPassword', true)
        ->set('primaryPassword', 'P@ssword123456')
        ->call('savePrimaryPassword')
        ->assertHasNoErrors()
        ->assertSet('showPasswordModal', true)
        ->assertSet('generatedPassword', 'P@ssword123456')
        ->assertSee('SFTP access enabled and password set for alicecorp');
});

test('setting SFTP password validates minimum 12 characters', function () {
    Livewire::actingAs($this->customer1)
        ->test(FtpManager::class, ['account' => $this->account1])
        ->call('openPrimaryPasswordModal')
        ->set('primaryPassword', 'short789')
        ->call('savePrimaryPassword')
        ->assertHasErrors(['primaryPassword']);
});

test('customer can run SFTP diagnostic and see effective sshd configuration', function () {
    $mockClient = Mockery::mock(AgentClient::class);

    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'sshd.effective' &&
                $server->id === $this->server->id &&
                $payload['username'] === 'alicecorp';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'username' => 'alicecorp',
                'user_exists' => true,
                'password_authentication' => 'yes',
                'force_command' => 'internal-sftp -u 022',
                'chroot_directory' => 'none',
                'effective_output' => "passwordauthentication yes\nforcecommand internal-sftp -u 022\nchrootdirectory none",
            ],
            'logs' => [],
        ]);

    app()->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer1)
        ->test(FtpManager::class, ['account' => $this->account1])
        ->call('runDiagnostic')
        ->assertSet('showDiagnosticModal', true)
        ->assertSet('sftpDiagnostic.password_authentication', 'yes')
        ->assertSet('sftpDiagnostic.force_command', 'internal-sftp -u 022')
        ->assertSet('sftpDiagnostic.chroot_directory', 'none')
        ->assertSet('sftpDiagnostic.user_exists', true)
        ->assertSee('internal-sftp -u 022')
        ->assertSee('Root-ownership conflict avoided');
});

test('customer cannot manage or run diagnostic on another customer account', function () {
    Livewire::actingAs($this->customer1)
        ->test(FtpManager::class, ['account' => $this->account2])
        ->assertForbidden();
});

test('admin can run SFTP diagnostic from ServerList component', function () {
    $mockClient = Mockery::mock(AgentClient::class);

    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'sshd.effective' &&
                $server->id === $this->server->id &&
                $payload['username'] === 'alicecorp';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'username' => 'alicecorp',
                'user_exists' => true,
                'password_authentication' => 'yes',
                'force_command' => 'internal-sftp -u 022',
                'chroot_directory' => 'none',
                'effective_output' => "passwordauthentication yes\nforcecommand internal-sftp -u 022\nchrootdirectory none",
            ],
            'logs' => [],
        ]);

    app()->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->admin)
        ->test(ServerList::class)
        ->call('openSftpDiagnosticModal', $this->server->id)
        ->assertSet('showSftpDiagnosticModal', true)
        ->assertSet('sftpDiagnosticUsername', 'alicecorp')
        ->call('runSftpDiagnostic')
        ->assertSet('sftpDiagnosticResult.password_authentication', 'yes')
        ->assertSet('sftpDiagnosticResult.force_command', 'internal-sftp -u 022')
        ->assertSee('internal-sftp -u 022');
});

test('non-admin customer cannot access ServerList component', function () {
    Livewire::actingAs($this->customer1)
        ->test(ServerList::class)
        ->assertForbidden();
});

