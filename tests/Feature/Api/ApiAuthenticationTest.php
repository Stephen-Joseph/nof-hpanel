<?php

use App\Models\ApiToken;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $tokenData = ApiToken::createToken($this->admin, 'Test API Token');
    $this->plainToken = $tokenData['plainTextToken'];
    $this->apiToken = $tokenData['token'];
});

test('request without Authorization header returns 401 UNAUTHENTICATED', function () {
    $response = $this->getJson('/api/v1/packages');

    $response->assertStatus(401)
        ->assertJson([
            'error' => [
                'code' => 'UNAUTHENTICATED',
            ],
        ]);
});

test('request with invalid token returns 401 INVALID_TOKEN', function () {
    $response = $this->withHeader('Authorization', 'Bearer invalid-token-123')
        ->getJson('/api/v1/packages');

    $response->assertStatus(401)
        ->assertJson([
            'error' => [
                'code' => 'INVALID_TOKEN',
            ],
        ]);
});

test('request with revoked token returns 401 TOKEN_REVOKED', function () {
    $this->apiToken->revoke();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->plainToken)
        ->getJson('/api/v1/packages');

    $response->assertStatus(401)
        ->assertJson([
            'error' => [
                'code' => 'TOKEN_REVOKED',
            ],
        ]);
});

test('request with expired token returns 401 TOKEN_EXPIRED', function () {
    $this->apiToken->update(['expires_at' => now()->subDay()]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->plainToken)
        ->getJson('/api/v1/packages');

    $response->assertStatus(401)
        ->assertJson([
            'error' => [
                'code' => 'TOKEN_EXPIRED',
            ],
        ]);
});

test('request from non-whitelisted IP returns 403 IP_NOT_ALLOWED', function () {
    $this->apiToken->update(['ip_allowlist' => ['192.168.1.50']]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->plainToken)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->getJson('/api/v1/packages');

    $response->assertStatus(403)
        ->assertJson([
            'error' => [
                'code' => 'IP_NOT_ALLOWED',
            ],
        ]);
});

test('valid request authenticates successfully', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->plainToken)
        ->getJson('/api/v1/packages');

    $response->assertStatus(200);

    $this->apiToken->refresh();
    expect($this->apiToken->last_used_at)->not->toBeNull();
});
