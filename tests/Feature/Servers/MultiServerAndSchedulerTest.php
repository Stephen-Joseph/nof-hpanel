<?php

use App\Enums\AccountStatus;
use App\Enums\ServerStatus;
use App\Exceptions\NoAvailableServerException;
use App\Jobs\MigrateAccountServerJob;
use App\Livewire\Servers\ServerList;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerEnrollmentToken;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\AgentClient;
use App\Services\ServerScheduler;
use DomainException;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

    $this->groupUS = ServerGroup::create([
        'name' => 'US East Cluster',
        'slug' => 'us-east',
        'location' => 'US-East',
    ]);

    $this->groupEU = ServerGroup::create([
        'name' => 'EU Central Cluster',
        'slug' => 'eu-central',
        'location' => 'EU-Central',
    ]);

    $this->server1 = Server::create([
        'name' => 'Node US 1',
        'hostname' => 'node-us-1.example.com',
        'private_ip' => '10.0.1.1',
        'agent_url' => 'https://10.0.1.1:8443',
        'status' => ServerStatus::ACTIVE,
        'server_group_id' => $this->groupUS->id,
        'location' => 'US-East',
        'max_accounts' => 5,
    ]);

    $this->server2 = Server::create([
        'name' => 'Node US 2',
        'hostname' => 'node-us-2.example.com',
        'private_ip' => '10.0.1.2',
        'agent_url' => 'https://10.0.1.2:8443',
        'status' => ServerStatus::ACTIVE,
        'server_group_id' => $this->groupUS->id,
        'location' => 'US-East',
        'max_accounts' => 5,
    ]);

    $this->package = Package::factory()->create();
    $this->customer = User::factory()->customer()->create();
});

test('scheduler picks least allocated active server', function () {
    // Add 2 accounts to server1, 1 account to server2
    Account::factory()->create(['server_id' => $this->server1->id, 'package_id' => $this->package->id]);
    Account::factory()->create(['server_id' => $this->server1->id, 'package_id' => $this->package->id]);
    Account::factory()->create(['server_id' => $this->server2->id, 'package_id' => $this->package->id]);

    $scheduler = new ServerScheduler;
    $chosen = $scheduler->selectServer();

    // Should choose server2 because it has only 1 account
    expect($chosen->id)->toBe($this->server2->id);
});

test('scheduler never selects a draining or maintenance server', function () {
    // server2 has fewer accounts but is set to DRAINING
    $this->server2->update(['status' => ServerStatus::DRAINING]);

    $scheduler = new ServerScheduler;
    $chosen = $scheduler->selectServer();

    // Must choose server1
    expect($chosen->id)->toBe($this->server1->id);

    // If server1 is maintenance, scheduler throws exception
    $this->server1->update(['status' => ServerStatus::MAINTENANCE]);
    $this->expectException(NoAvailableServerException::class);
    $scheduler->selectServer();
});

test('scheduler respects cluster group filter', function () {
    $serverEU = Server::create([
        'name' => 'Node EU 1',
        'hostname' => 'node-eu-1.example.com',
        'private_ip' => '10.0.2.1',
        'agent_url' => 'https://10.0.2.1:8443',
        'status' => ServerStatus::ACTIVE,
        'server_group_id' => $this->groupEU->id,
        'location' => 'EU-Central',
        'max_accounts' => 10,
    ]);

    $scheduler = new ServerScheduler;
    $chosen = $scheduler->selectServer(serverGroupId: $this->groupEU->id);

    expect($chosen->id)->toBe($serverEU->id);
});

test('admin can toggle server drain mode in server list', function () {
    Livewire::actingAs($this->admin)
        ->test(ServerList::class)
        ->call('toggleDrain', $this->server1->id);

    $this->server1->refresh();
    expect($this->server1->status)->toBe(ServerStatus::DRAINING);

    // Toggle back
    Livewire::actingAs($this->admin)
        ->test(ServerList::class)
        ->call('toggleDrain', $this->server1->id);

    $this->server1->refresh();
    expect($this->server1->status)->toBe(ServerStatus::ACTIVE);
});

test('server enrollment api registers new node with valid one-time token', function () {
    $tokenStr = Str::random(64);

    $tokenRecord = ServerEnrollmentToken::create([
        'token' => $tokenStr,
        'server_name' => 'Frankfurt-Edge-01',
        'server_group_id' => $this->groupEU->id,
        'created_by' => $this->admin->id,
        'expires_at' => now()->addHours(24),
    ]);

    $response = $this->postJson(route('api.v1.servers.enroll'), [
        'token' => $tokenStr,
        'hostname' => 'fra01.example.com',
        'private_ip' => '10.0.3.1',
        'agent_url' => 'https://10.0.3.1:8443',
        'cpu_cores' => 8,
        'memory_mb' => 16384,
        'storage_mb' => 204800,
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'server' => [
                'name' => 'Frankfurt-Edge-01',
                'hostname' => 'fra01.example.com',
                'private_ip' => '10.0.3.1',
                'status' => 'active',
            ],
        ]);

    $this->assertDatabaseHas('servers', [
        'hostname' => 'fra01.example.com',
        'private_ip' => '10.0.3.1',
        'status' => 'active',
    ]);

    $tokenRecord->refresh();
    expect($tokenRecord->used_at)->not->toBeNull();

    // Reusing the token fails
    $retry = $this->postJson(route('api.v1.servers.enroll'), [
        'token' => $tokenStr,
        'hostname' => 'fra02.example.com',
        'private_ip' => '10.0.3.2',
        'agent_url' => 'https://10.0.3.2:8443',
    ]);

    $retry->assertStatus(422)
        ->assertJson(['error' => ['code' => 'INVALID_TOKEN']]);
});

test('migrate account server job transfers account and updates server assignment', function () {
    $account = Account::factory()->create([
        'server_id' => $this->server1->id,
        'package_id' => $this->package->id,
        'user_id' => $this->customer->id,
        'linux_username' => 'migrateduser',
        'primary_domain' => 'migrateduser.com',
        'status' => AccountStatus::ACTIVE,
    ]);

    $account->domains()->create([
        'domain' => 'migrateduser.com',
        'document_root' => '/home/migrateduser/public_html',
    ]);

    $mockClient = Mockery::mock(AgentClient::class);

    // 1. backup.create on source
    $mockClient->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn ($srv) => $srv->id === $this->server1->id),
            'backup.create',
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn([
            'success' => true,
            'data' => ['snapshot_id' => 'snap_mig_123'],
            'logs' => ['backup created'],
        ]);

    // 2. account.create on target
    $mockClient->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn ($srv) => $srv->id === $this->server2->id),
            'account.create',
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn([
            'success' => true,
            'data' => ['created' => true],
            'logs' => ['account shell provisioned'],
        ]);

    // 3. backup.restore on target
    $mockClient->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn ($srv) => $srv->id === $this->server2->id),
            'backup.restore',
            Mockery::on(fn ($p) => $p['snapshot_id'] === 'snap_mig_123'),
            Mockery::any()
        )
        ->andReturn([
            'success' => true,
            'data' => ['restored' => true],
            'logs' => ['data restored on target'],
        ]);

    // 4. vhost.write on target
    $mockClient->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn ($srv) => $srv->id === $this->server2->id),
            'vhost.write',
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn([
            'success' => true,
            'data' => ['vhost' => true],
            'logs' => ['vhost written on target'],
        ]);

    $job = new MigrateAccountServerJob($account, $this->server2);
    $job->handle($mockClient);

    $account->refresh();
    expect($account->server_id)->toBe($this->server2->id);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'account.migrated',
        'target_id' => $account->id,
    ]);
});

test('migrate account rejects migration to same server', function () {
    $account = Account::factory()->create([
        'server_id' => $this->server1->id,
        'package_id' => $this->package->id,
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    $job = new MigrateAccountServerJob($account, $this->server1);

    $this->expectException(DomainException::class);
    $job->handle($mockClient);
});
