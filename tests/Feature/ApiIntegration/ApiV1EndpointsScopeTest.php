<?php

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->admin = User::factory()->admin()->create();
    $fullTokenData = ApiToken::createToken($this->admin, 'Full Token', ['*']);
    $this->fullToken = $fullTokenData['plainTextToken'];
    $this->apiTokenModel = $fullTokenData['token'];

    // Specific scoped tokens
    $this->healthOnlyToken = ApiToken::createToken($this->admin, 'Health Only', ['health:read'])['plainTextToken'];
    $this->accountsOnlyToken = ApiToken::createToken($this->admin, 'Accounts Read Only', ['accounts:read'])['plainTextToken'];
    $this->accountsWriteToken = ApiToken::createToken($this->admin, 'Accounts Write Only', ['accounts:write'])['plainTextToken'];

    $this->server = Server::factory()->create(['status' => 'active']);
    $this->package = Package::factory()->create();
    $this->customer = User::factory()->customer()->create();

    $this->account = Account::factory()->active()->create([
        'user_id' => $this->customer->id,
        'package_id' => $this->package->id,
        'server_id' => $this->server->id,
        'linux_username' => 'testscoped',
        'primary_domain' => 'testscoped.com',
    ]);
});

test('unauthenticated request returns 401', function () {
    $this->getJson('/api/v1/health')->assertStatus(401);
    $this->getJson('/api/v1/packages')->assertStatus(401);
    $this->getJson("/api/v1/accounts/{$this->account->id}")->assertStatus(401);
    $this->postJson('/api/v1/accounts', [])->assertStatus(401);
});

test('revoked token returns 401', function () {
    $this->apiTokenModel->revoke();

    $this->withHeader('Authorization', 'Bearer '.$this->fullToken)
        ->getJson('/api/v1/health')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'TOKEN_REVOKED');
});

test('endpoint rejects token with wrong scope with 403 INSUFFICIENT_SCOPE', function () {
    // Health requires health:read, accountsOnlyToken has only accounts:read
    $res = $this->withHeader('Authorization', 'Bearer '.$this->accountsOnlyToken)
        ->getJson('/api/v1/health');

    $res->assertStatus(403)
        ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');

    // POST /accounts requires accounts:write, accountsOnlyToken has only accounts:read
    $res2 = $this->withHeader('Authorization', 'Bearer '.$this->accountsOnlyToken)
        ->postJson('/api/v1/accounts', [
            'username' => 'scopetest',
            'domain' => 'scopetest.com',
            'package_id' => $this->package->id,
            'customer_email' => 'scope@example.com',
        ]);

    $res2->assertStatus(403)
        ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');
});

test('endpoints validate payload and return 422 on invalid input', function () {
    // POST /accounts with missing required fields
    $res = $this->withHeader('Authorization', 'Bearer '.$this->accountsWriteToken)
        ->postJson('/api/v1/accounts', []);

    $res->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    expect($res->json('error.details.username'))->not->toBeEmpty();
    expect($res->json('error.details.domain'))->not->toBeEmpty();

    // POST /domains with missing fields
    $fullTokenData = ApiToken::createToken($this->admin, 'Full', ['*']);
    $resDomains = $this->withHeader('Authorization', 'Bearer '.$fullTokenData['plainTextToken'])
        ->postJson('/api/v1/domains', []);

    $resDomains->assertStatus(422);
});

test('valid token with correct scope returns 2xx', function () {
    Http::fake([
        '*' => Http::response(['success' => true, 'data' => ['agent_version' => '0.1.0']], 200),
    ]);

    // GET /health
    $this->withHeader('Authorization', 'Bearer '.$this->healthOnlyToken)
        ->getJson('/api/v1/health')
        ->assertStatus(200);

    // GET /accounts/{id}
    $this->withHeader('Authorization', 'Bearer '.$this->accountsOnlyToken)
        ->getJson("/api/v1/accounts/{$this->account->id}")
        ->assertStatus(200);

    // POST /accounts with valid payload
    $res = $this->withHeader('Authorization', 'Bearer '.$this->accountsWriteToken)
        ->postJson('/api/v1/accounts', [
            'username' => 'newvalidusr',
            'domain' => 'newvalidusr.com',
            'package_id' => $this->package->id,
            'customer_email' => 'newvalid@example.com',
        ]);

    $res->assertStatus(202);
});
