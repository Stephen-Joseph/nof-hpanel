<?php

use App\Models\ServerEnrollmentToken;
use App\Models\User;

test('server enrollment endpoint enforces strict rate limiting', function () {
    $admin = User::factory()->admin()->create();

    $token = ServerEnrollmentToken::create([
        'server_name' => 'node-test.example.com',
        'created_by' => $admin->id,
        'token' => 'a1b2c3d4e5f67890123456789012345678901234567890123456789012345678',
        'expires_at' => now()->addHour(),
    ]);

    $payload = [
        'token' => $token->token,
        'name' => 'node-test.example.com',
        'ip_address' => '10.0.0.10',
        'agent_port' => 8443,
    ];

    // Throttled at 5 requests per minute
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(route('api.v1.servers.enroll'), $payload);
    }

    // 6th attempt should be blocked with 429 Too Many Requests
    $response = $this->postJson(route('api.v1.servers.enroll'), $payload);
    $response->assertStatus(429);
});
