<?php

use App\Enums\AccountStatus;
use App\Jobs\ProvisionAccount;
use App\Jobs\SuspendAccount;
use App\Jobs\TerminateAccount;
use App\Jobs\UnsuspendAccount;
use App\Jobs\UpgradeAccount;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->admin = User::factory()->admin()->create();
    $tokenData = ApiToken::createToken($this->admin, 'Full Access Token');
    $this->token = $tokenData['plainTextToken'];

    $this->server = Server::factory()->create(['status' => 'active', 'name' => 'Primary Server']);
    $this->package = Package::factory()->create([
        'name' => 'Business Plan',
        'storage_mb' => 20480,
    ]);

    $this->customer = User::factory()->customer()->create();
    $this->account = Account::factory()->active()->create([
        'user_id' => $this->customer->id,
        'package_id' => $this->package->id,
        'server_id' => $this->server->id,
        'linux_username' => 'apiacc01',
        'primary_domain' => 'apiacc01.org',
    ]);
});

test('GET /api/v1/health returns panel and servers health', function () {
    Http::fake([
        '*' => Http::response([
            'success' => true,
            'data' => ['agent_version' => '0.1.0'],
        ], 200),
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/v1/health');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'healthy',
            'panel' => ['status' => 'ok'],
        ])
        ->assertJsonStructure(['servers']);
});

test('GET /api/v1/packages returns available package list', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/v1/packages');

    $response->assertStatus(200)
        ->assertJsonStructure(['packages' => [['id', 'name', 'storage_mb']]]);
});

test('POST /api/v1/accounts enqueues provisioning and returns 202', function () {
    $payload = [
        'username' => 'apicust99',
        'domain' => 'apicust99.com',
        'package_id' => $this->package->id,
        'customer_email' => 'newcust@example.com',
        'customer_name' => 'New Customer',
    ];

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/v1/accounts', $payload);

    $response->assertStatus(202)
        ->assertJson([
            'status' => 'provisioning',
            'username' => 'apicust99',
            'domain' => 'apicust99.com',
        ]);

    $this->assertDatabaseHas('accounts', [
        'linux_username' => 'apicust99',
        'primary_domain' => 'apicust99.com',
        'status' => AccountStatus::PENDING->value,
    ]);

    Queue::assertPushed(ProvisionAccount::class);
});

test('GET /api/v1/accounts/{id} returns account details', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/v1/accounts/{$this->account->id}");

    $response->assertStatus(200)
        ->assertJson([
            'id' => $this->account->id,
            'username' => 'apiacc01',
            'primary_domain' => 'apiacc01.org',
            'status' => 'active',
        ]);
});

test('POST /api/v1/accounts/{id}/suspend enqueues suspension', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson("/api/v1/accounts/{$this->account->id}/suspend", [
            'reason' => 'Abuse complaint #502',
        ]);

    $response->assertStatus(200)
        ->assertJson(['status' => 'suspending']);

    Queue::assertPushed(SuspendAccount::class, function ($job) {
        return $job->account->id === $this->account->id && $job->reason === 'Abuse complaint #502';
    });
});

test('POST /api/v1/accounts/{id}/unsuspend enqueues unsuspension', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson("/api/v1/accounts/{$this->account->id}/unsuspend");

    $response->assertStatus(200)
        ->assertJson(['status' => 'unsuspending']);

    Queue::assertPushed(UnsuspendAccount::class);
});

test('POST /api/v1/accounts/{id}/upgrade enqueues upgrade', function () {
    $newPackage = Package::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson("/api/v1/accounts/{$this->account->id}/upgrade", [
            'package_id' => $newPackage->id,
        ]);

    $response->assertStatus(200)
        ->assertJson(['status' => 'upgrading']);

    Queue::assertPushed(UpgradeAccount::class);
});

test('DELETE /api/v1/accounts/{id} enqueues termination and returns 202', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->deleteJson("/api/v1/accounts/{$this->account->id}");

    $response->assertStatus(202)
        ->assertJson(['status' => 'terminating']);

    Queue::assertPushed(TerminateAccount::class);
});

test('GET /api/v1/accounts/{id}/usage returns quota usage metrics', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/v1/accounts/{$this->account->id}/usage");

    $response->assertStatus(200)
        ->assertJsonStructure(['account_id', 'usage' => ['storage_mb', 'inodes', 'bandwidth_gb']]);
});

test('POST /api/v1/accounts/{id}/password updates customer password', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson("/api/v1/accounts/{$this->account->id}/password", [
            'password' => 'NewSecurePassword123!',
        ]);

    $response->assertStatus(200)
        ->assertJson(['status' => 'password_updated']);
});

test('POST /api/v1/accounts/{id}/sso generates signed temporary URL', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson("/api/v1/accounts/{$this->account->id}/sso");

    $response->assertStatus(200)
        ->assertJsonStructure(['sso_url', 'expires_in_seconds']);
});

test('POST /api/v1/domains adds domain', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/v1/domains', [
            'account_id' => $this->account->id,
            'domain' => 'sub.apiacc01.org',
        ]);

    $response->assertStatus(201)
        ->assertJson([
            'status' => 'domain_added',
            'domain' => 'sub.apiacc01.org',
        ]);
});

test('POST /api/v1/ssl/issue requests certificate issuance', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/v1/ssl/issue', [
            'domain' => 'apiacc01.org',
            'account_id' => $this->account->id,
        ]);

    $response->assertStatus(202)
        ->assertJson([
            'status' => 'issuing',
            'domain' => 'apiacc01.org',
        ]);
});
