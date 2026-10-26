<?php

use App\Enums\ServerStatus;
use App\Models\Account;
use App\Models\Database;
use App\Models\Package;
use App\Models\PmaSsoSession;
use App\Models\PmaSsoToken;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Mockery;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'status' => ServerStatus::ACTIVE,
        'hostname' => 'node1.example.com',
        'private_ip' => '10.0.0.10',
    ]);

    $this->package = Package::factory()->create([
        'max_databases' => 5,
        'max_db_users' => 5,
    ]);

    $this->customer1 = User::factory()->customer()->create();
    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
    ]);

    $this->db1 = Database::create([
        'account_id' => $this->account1->id,
        'name' => 'alicecorp_wp',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $this->customer2 = User::factory()->customer()->create();
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobinc',
        'primary_domain' => 'bobinc.com',
    ]);

    $this->db2 = Database::create([
        'account_id' => $this->account2->id,
        'name' => 'bobinc_shop',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $this->sharedSecret = 'test-secret-key-12345678901234567890';
    Config::set('hpanel.pma_shared_secret', $this->sharedSecret);
    Config::set('hpanel.pma_url', 'https://pma.nexorafox.in');
});

test('customer can launch PMA SSO and receives redirect with token', function () {
    $response = $this->actingAs($this->customer1)
        ->get(route('accounts.databases.pma_sso', [
            'account' => $this->account1,
            'db' => 'alicecorp_wp',
        ]));

    $response->assertRedirect();
    $targetUrl = $response->headers->get('Location');
    $this->assertStringStartsWith('https://pma.nexorafox.in/signon.php?token=', $targetUrl);

    // Extract token from redirect URL
    $parts = parse_url($targetUrl);
    parse_str($parts['query'], $query);
    $rawToken = $query['token'] ?? '';

    $this->assertEquals(64, strlen($rawToken));

    // Verify token was stored hashed (sha256) in database
    $tokenRecord = PmaSsoToken::where('account_id', $this->account1->id)->latest()->first();
    $this->assertNotNull($tokenRecord);
    $this->assertEquals(hash('sha256', $rawToken), $tokenRecord->token_hash);
    $this->assertEquals('alicecorp_wp', $tokenRecord->target_db);
    $this->assertNull($tokenRecord->used_at);
    $this->assertTrue($tokenRecord->isValid());
});

test('cross-tenant access is denied for launching PMA SSO', function () {
    // Customer 1 cannot launch SSO for Customer 2's account
    $this->actingAs($this->customer1)
        ->get(route('accounts.databases.pma_sso', ['account' => $this->account2]))
        ->assertForbidden();

    // Customer 1 cannot request SSO on account 1 with Customer 2's database target
    $this->actingAs($this->customer1)
        ->get(route('accounts.databases.pma_sso', [
            'account' => $this->account1,
            'db' => 'bobinc_shop',
        ]))
        ->assertForbidden();
});

test('redeem endpoint enforces single-use token', function () {
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $tokenRecord = PmaSsoToken::create([
        'account_id' => $this->account1->id,
        'user_id' => $this->customer1->id,
        'token_hash' => $tokenHash,
        'target_db' => 'alicecorp_wp',
        'ip_address' => '127.0.0.1',
        'expires_at' => now()->addSeconds(60),
    ]);

    // Mock AgentClient
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $server->id === $this->server->id
                && $action === 'db.sso_create'
                && $payload['account_username'] === 'alicecorp'
                && $payload['database'] === 'alicecorp_wp';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'db_user' => 'pma_testuser01',
                'db_password' => 'TempSecretPass2026!',
                'database' => 'alicecorp_wp',
            ],
            'logs' => ['created temporary MariaDB SSO user'],
        ]);
    $this->app->instance(AgentClient::class, $mockClient);

    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$rawToken, $this->sharedSecret);

    // 1. First redemption succeeds
    $response = $this->withHeaders([
        'X-Timestamp' => $timestamp,
        'X-Signature' => $signature,
    ])->postJson('/internal/pma/redeem', [
        'token' => $rawToken,
    ], ['REMOTE_ADDR' => '127.0.0.1']);

    $response->assertOk()
        ->assertJson([
            'user' => 'pma_testuser01',
            'password' => 'TempSecretPass2026!',
            'database' => 'alicecorp_wp',
        ]);

    $tokenRecord->refresh();
    $this->assertNotNull($tokenRecord->used_at);
    $this->assertFalse($tokenRecord->isValid());

    // 2. Second redemption fails with 403 Forbidden
    $secondResponse = $this->withHeaders([
        'X-Timestamp' => $timestamp,
        'X-Signature' => $signature,
    ])->postJson('/internal/pma/redeem', [
        'token' => $rawToken,
    ], ['REMOTE_ADDR' => '127.0.0.1']);

    $secondResponse->assertForbidden();
});

test('redeem endpoint rejects expired tokens', function () {
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    PmaSsoToken::create([
        'account_id' => $this->account1->id,
        'user_id' => $this->customer1->id,
        'token_hash' => $tokenHash,
        'target_db' => 'alicecorp_wp',
        'ip_address' => '127.0.0.1',
        'expires_at' => now()->subSeconds(10), // expired
    ]);

    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$rawToken, $this->sharedSecret);

    $this->withHeaders([
        'X-Timestamp' => $timestamp,
        'X-Signature' => $signature,
    ])->postJson('/internal/pma/redeem', [
        'token' => $rawToken,
    ], ['REMOTE_ADDR' => '127.0.0.1'])
        ->assertForbidden();
});

test('redeem endpoint rejects requests from non-loopback IP', function () {
    $rawToken = bin2hex(random_bytes(32));
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$rawToken, $this->sharedSecret);

    // Call from external IP
    $this->withHeaders([
        'X-Timestamp' => $timestamp,
        'X-Signature' => $signature,
    ])->postJson('/internal/pma/redeem', [
        'token' => $rawToken,
    ], ['REMOTE_ADDR' => '203.0.113.50'])
        ->assertForbidden();
});

test('redeem endpoint rejects invalid HMAC signature or expired timestamp skew', function () {
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    PmaSsoToken::create([
        'account_id' => $this->account1->id,
        'user_id' => $this->customer1->id,
        'token_hash' => $tokenHash,
        'target_db' => 'alicecorp_wp',
        'ip_address' => '127.0.0.1',
        'expires_at' => now()->addSeconds(60),
    ]);

    // 1. Wrong signature
    $this->withHeaders([
        'X-Timestamp' => time(),
        'X-Signature' => 'invalid-hmac-signature-abcdef',
    ])->postJson('/internal/pma/redeem', [
        'token' => $rawToken,
    ], ['REMOTE_ADDR' => '127.0.0.1'])
        ->assertForbidden();

    // 2. Timestamp skew > 30 seconds
    $oldTimestamp = time() - 45;
    $sigWithOldTimestamp = hash_hmac('sha256', $oldTimestamp.'.'.$rawToken, $this->sharedSecret);

    $this->withHeaders([
        'X-Timestamp' => $oldTimestamp,
        'X-Signature' => $sigWithOldTimestamp,
    ])->postJson('/internal/pma/redeem', [
        'token' => $rawToken,
    ], ['REMOTE_ADDR' => '127.0.0.1'])
        ->assertForbidden();
});

test('no plaintext passwords or credentials are stored in database', function () {
    // Assert schema does not contain any password column in pma tables
    $tokenColumns = Schema::getColumnListing('pma_sso_tokens');
    $this->assertNotContains('password', $tokenColumns);
    $this->assertNotContains('db_password', $tokenColumns);

    $sessionColumns = Schema::getColumnListing('pma_sso_sessions');
    $this->assertNotContains('password', $sessionColumns);
    $this->assertNotContains('db_password', $sessionColumns);

    // Create session record and assert database content
    $session = PmaSsoSession::create([
        'account_id' => $this->account1->id,
        'user_id' => $this->customer1->id,
        'db_user' => 'pma_testuser01',
        'target_db' => 'alicecorp_wp',
        'expires_at' => now()->addMinutes(30),
    ]);

    $rawRecord = (array) \Illuminate\Support\Facades\DB::table('pma_sso_sessions')->where('id', $session->id)->first();
    foreach ($rawRecord as $col => $val) {
        $this->assertStringNotContainsString('password', strtolower($col));
    }
});

test('PrunePmaSsoSessionsJob revokes expired sessions and updates revoked_at', function () {
    $expiredSession = PmaSsoSession::create([
        'account_id' => $this->account1->id,
        'user_id' => $this->customer1->id,
        'db_user' => 'pma_expireduser1',
        'target_db' => 'alicecorp_wp',
        'expires_at' => now()->subMinutes(5),
    ]);

    $activeSession = PmaSsoSession::create([
        'account_id' => $this->account1->id,
        'user_id' => $this->customer1->id,
        'db_user' => 'pma_activeuser2',
        'target_db' => 'alicecorp_wp',
        'expires_at' => now()->addMinutes(25),
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $server->id === $this->server->id
                && $action === 'db.sso_revoke'
                && $payload['db_user'] === 'pma_expireduser1';
        })
        ->andReturn([
            'success' => true,
            'data' => ['db_user' => 'pma_expireduser1', 'revoked' => true],
            'logs' => ['revoked temporary MariaDB SSO user'],
        ]);
    $this->app->instance(AgentClient::class, $mockClient);

    dispatch_sync(new \App\Jobs\PrunePmaSsoSessionsJob);

    $expiredSession->refresh();
    $activeSession->refresh();

    $this->assertNotNull($expiredSession->revoked_at);
    $this->assertNull($activeSession->revoked_at);
});
