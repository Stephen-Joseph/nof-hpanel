<?php

use App\Models\ApiToken;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->admin = User::factory()->admin()->create();
    $tokenData = ApiToken::createToken($this->admin, 'Idempotency Tester');
    $this->token = $tokenData['plainTextToken'];

    $this->server = Server::factory()->create(['status' => 'active']);
    $this->package = Package::factory()->create();
});

test('subsequent request with same Idempotency-Key returns cached response', function () {
    $idempotencyKey = 'idemp-key-1001';
    $payload = [
        'username' => 'idempuser1',
        'domain' => 'idemp1.com',
        'package_id' => $this->package->id,
        'customer_email' => 'idemp1@example.com',
    ];

    // First Request
    $res1 = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/accounts', $payload);

    $res1->assertStatus(202)
        ->assertJson(['status' => 'provisioning']);

    $firstResponseBody = $res1->json();

    // Second Request with identical Idempotency-Key & Payload
    $res2 = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/accounts', $payload);

    $res2->assertStatus(202)
        ->assertHeader('X-Idempotent-Replay', 'true')
        ->assertExactJson($firstResponseBody);
});

test('request with same Idempotency-Key but different payload returns 409 IDEMPOTENCY_CONFLICT', function () {
    $idempotencyKey = 'idemp-conflict-test';

    // First Request
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/accounts', [
            'username' => 'idempuser2',
            'domain' => 'idemp2.com',
            'package_id' => $this->package->id,
            'customer_email' => 'idemp2@example.com',
        ])
        ->assertStatus(202);

    // Second Request with modified payload
    $res2 = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/accounts', [
            'username' => 'idempuser2',
            'domain' => 'DIFFERENT-DOMAIN.com',
            'package_id' => $this->package->id,
            'customer_email' => 'idemp2@example.com',
        ]);

    $res2->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'IDEMPOTENCY_CONFLICT',
            ],
        ]);
});
