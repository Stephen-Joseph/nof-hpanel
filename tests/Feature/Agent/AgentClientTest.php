<?php

use App\Exceptions\AgentClientException;
use App\Exceptions\AgentConnectionException;
use App\Exceptions\AgentSecurityException;
use App\Models\Server;
use App\Services\AgentClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.agent.secret', 'test-secret-key-12345');

    $this->server = Server::factory()->create([
        'name' => 'Edge Node 1',
        'hostname' => 'node1.internal',
        'agent_url' => 'https://10.0.0.10:8443',
    ]);

    $this->client = new AgentClient;
});

test('canonical string and signature match expected format', function () {
    $action = 'system.ping';
    $requestId = 'req-123';
    $timestamp = 1774000000;
    $nonce = 'nonce-abc';
    $dryRun = false;
    $payloadJson = '{}';
    $secret = 'test-secret-key-12345';

    $expectedHash = hash('sha256', $payloadJson);
    $canonical = $this->client->buildCanonicalString($action, $requestId, $timestamp, $nonce, $dryRun, $payloadJson);

    expect($canonical)->toBe("system.ping.req-123.1774000000.nonce-abc.0.{$expectedHash}");

    $signature = $this->client->sign($action, $requestId, $timestamp, $nonce, $dryRun, $payloadJson, $secret);
    $expectedSig = hash_hmac('sha256', $canonical, $secret);

    expect($signature)->toBe($expectedSig);
});

test('execute sends signed request and receives successful response', function () {
    Http::fake([
        'https://10.0.0.10:8443/action' => Http::response([
            'request_id' => 'req-001',
            'action' => 'system.ping',
            'success' => true,
            'data' => ['pong' => true, 'agent_version' => '0.1.0'],
            'logs' => ['system.ping executed successfully'],
            'executed_at' => 1774000000,
        ], 200),
    ]);

    $result = $this->client->execute($this->server, 'system.ping', [], false, 'req-001');

    expect($result['success'])->toBeTrue()
        ->and($result['data']['pong'])->toBeTrue()
        ->and($result['logs'])->toContain('system.ping executed successfully')
        ->and($result['request_id'])->toBe('req-001');

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://10.0.0.10:8443/action'
            && $body['action'] === 'system.ping'
            && $body['request_id'] === 'req-001'
            && isset($body['signature'])
            && isset($body['nonce']);
    });
});

test('signature tampering throws AgentSecurityException', function () {
    Http::fake([
        'https://10.0.0.10:8443/action' => Http::response([
            'error' => [
                'code' => 'INVALID_SIGNATURE',
                'message' => 'security: invalid HMAC-SHA256 signature',
            ],
        ], 401),
    ]);

    $this->client->execute($this->server, 'system.ping');
})->throws(AgentSecurityException::class, 'Agent security rejection [INVALID_SIGNATURE]');

test('replayed nonce throws AgentSecurityException', function () {
    Http::fake([
        'https://10.0.0.10:8443/action' => Http::response([
            'error' => [
                'code' => 'REPLAY_DETECTED',
                'message' => 'security: replay detected, nonce has already been used',
            ],
        ], 401),
    ]);

    $this->client->execute($this->server, 'system.ping');
})->throws(AgentSecurityException::class, 'Agent security rejection [REPLAY_DETECTED]');

test('expired timestamp throws AgentSecurityException', function () {
    Http::fake([
        'https://10.0.0.10:8443/action' => Http::response([
            'error' => [
                'code' => 'TIMESTAMP_EXPIRED',
                'message' => 'security: request timestamp is older than allowed window or too far in the future',
            ],
        ], 401),
    ]);

    $this->client->execute($this->server, 'system.ping');
})->throws(AgentSecurityException::class, 'Agent security rejection [TIMESTAMP_EXPIRED]');

test('unknown action throws AgentClientException with 404', function () {
    Http::fake([
        'https://10.0.0.10:8443/action' => Http::response([
            'error' => [
                'code' => 'UNKNOWN_ACTION',
                'message' => "Action 'system.unregistered' is not registered",
            ],
        ], 404),
    ]);

    $this->client->execute($this->server, 'system.unregistered');
})->throws(AgentClientException::class, 'Unknown action requested');

test('invalid payload throws AgentClientException with 422', function () {
    Http::fake([
        'https://10.0.0.10:8443/action' => Http::response([
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'field username is required and must match regex',
            ],
        ], 422),
    ]);

    $this->client->execute($this->server, 'account.create', ['bad' => 'payload']);
})->throws(AgentClientException::class, 'Agent payload rejection (422)');

test('connection failure retries up to max attempts before throwing AgentConnectionException', function () {
    $attemptsCount = 0;
    Http::fake([
        'https://10.0.0.10:8443/action' => function () use (&$attemptsCount) {
            $attemptsCount++;
            throw new ConnectionException('Connection refused');
        },
    ]);

    try {
        $this->client->execute($this->server, 'system.ping', [], false, null, 3);
        $this->fail('Expected AgentConnectionException was not thrown');
    } catch (AgentConnectionException $e) {
        expect($attemptsCount)->toBe(3)
            ->and($e->getMessage())->toContain('failed to respond to action system.ping after 3 attempts');
    }
});

test('ping and health helpers invoke the expected actions', function () {
    Http::fake([
        'https://10.0.0.10:8443/action' => Http::sequence()
            ->push(['success' => true, 'data' => ['pong' => true], 'logs' => []], 200)
            ->push(['success' => true, 'data' => ['cpu_cores' => 8, 'os' => 'linux'], 'logs' => []], 200),
    ]);

    $pingResult = $this->client->ping($this->server);
    expect($pingResult['success'])->toBeTrue()
        ->and($pingResult['data']['pong'])->toBeTrue();

    $healthResult = $this->client->health($this->server);
    expect($healthResult['success'])->toBeTrue()
        ->and($healthResult['data']['cpu_cores'])->toBe(8);
});

test('production environment refuses to execute with default or empty HMAC secret', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('services.agent.secret', 'test-agent-secret-key-change-me');

    $this->client->execute($this->server, 'system.ping');
})->throws(\RuntimeException::class, 'PANEL_AGENT_HMAC_SECRET must be configured to a non-default value in production.');

test('signed payload with slash and unicode transmits identical serialized bytes in request body', function () {
    Http::fake([
        'https://10.0.0.10:8443/action' => Http::response([
            'request_id' => 'req-slash-unicode',
            'action' => 'file.write',
            'success' => true,
            'data' => ['bytes' => 42],
            'logs' => [],
        ], 200),
    ]);

    $payload = [
        'path' => '/home/alicecorp/public_html/föö/bär/index.php',
        'content' => '<?php echo "Hello / 世界 / café!";',
    ];

    $expectedPayloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $this->client->execute($this->server, 'file.write', $payload, false, 'req-slash-unicode');

    Http::assertSent(function (Request $request) use ($expectedPayloadJson, $payload) {
        $rawBody = $request->body();

        // 1. Verify the transmitted raw HTTP body contains the exact unescaped payload JSON bytes
        expect($rawBody)->toContain('"payload":'.$expectedPayloadJson);

        // 2. Verify slashes and unicode are NOT escaped in the transmitted body
        expect($rawBody)->toContain('/home/alicecorp/public_html/föö/bär/index.php')
            ->and($rawBody)->toContain('Hello / 世界 / café!')
            ->and($rawBody)->not->toContain('\/home\/alicecorp')
            ->and($rawBody)->not->toContain('\u');

        // 3. Extract the transmitted payload from the raw body and verify it matches the signed string
        preg_match('/"payload":(.*?),"signature"/s', $rawBody, $matches);
        expect($matches[1])->toBe($expectedPayloadJson);

        // 4. Verify signature on the request was computed using the exact signed payload string
        $data = $request->data();
        $expectedSignature = $this->client->sign(
            'file.write',
            'req-slash-unicode',
            $data['timestamp'],
            $data['nonce'],
            false,
            $expectedPayloadJson,
            'test-secret-key-12345'
        );

        expect($data['signature'])->toBe($expectedSignature)
            ->and($data['payload'])->toBe($payload);

        return true;
    });
});

test('empty payload serializes to {} in both signed string and transmitted body', function () {
    Http::fake([
        'https://10.0.0.10:8443/action' => Http::response([
            'request_id' => 'req-empty-payload',
            'action' => 'system.ping',
            'success' => true,
            'data' => ['pong' => true],
            'logs' => [],
        ], 200),
    ]);

    $this->client->execute($this->server, 'system.ping', [], false, 'req-empty-payload');

    Http::assertSent(function (Request $request) {
        $rawBody = $request->body();

        // 1. Transmitted body contains "payload":{}
        expect($rawBody)->toContain('"payload":{}');

        // 2. Extracted payload substring is exactly '{}'
        preg_match('/"payload":(.*?),"signature"/s', $rawBody, $matches);
        expect($matches[1])->toBe('{}');

        // 3. Signature matches signed '{}'
        $data = $request->data();
        $expectedSignature = $this->client->sign(
            'system.ping',
            'req-empty-payload',
            $data['timestamp'],
            $data['nonce'],
            false,
            '{}',
            'test-secret-key-12345'
        );

        expect($data['signature'])->toBe($expectedSignature);

        return true;
    });
});

test('production environment refuses default HMAC secret with explicit env var error message', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('services.agent.secret', 'test-agent-secret-key-change-me');

    expect(fn () => $this->client->execute($this->server, 'system.ping'))
        ->toThrow(\RuntimeException::class, 'PANEL_AGENT_HMAC_SECRET must be configured to a non-default value in production.');
});

test('production environment fails loudly if mTLS client cert, key, or CA is missing or unreadable', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('services.agent.secret', 'valid-production-secret-12345');
    config()->set('services.agent.client_cert_file', '/nonexistent/client.crt');
    config()->set('services.agent.client_key_file', '/nonexistent/client.key');
    config()->set('services.agent.tls_ca_file', '/nonexistent/ca.crt');

    expect(fn () => $this->client->execute($this->server, 'system.ping'))
        ->toThrow(\RuntimeException::class, 'PANEL_AGENT_CLIENT_CERT_FILE is missing or unreadable');
});

test('400, 422, and 500 error responses include agent message and details in exception text', function () {
    Http::fake([
        'https://10.0.0.10:8443/action' => Http::response([
            'request_id' => 'req-err-422',
            'action' => 'account.create',
            'error' => [
                'code' => 'STEP_FAILED',
                'message' => 'Quota initialization failed',
                'details' => 'quotactl unsupported on filesystem',
            ],
        ], 422),
    ]);

    expect(fn () => $this->client->execute($this->server, 'account.create', ['username' => 'testuser']))
        ->toThrow(\App\Exceptions\AgentClientException::class, 'Quota initialization failed | quotactl unsupported on filesystem');
});

test('hpanel:agent-check command runs dry-run ping and reports status', function () {
    Http::fake([
        'https://10.0.0.10:8443/action' => Http::response([
            'request_id' => 'req-ping-check',
            'action' => 'system.ping',
            'success' => true,
            'data' => ['pong' => true],
            'logs' => ['system.ping executed'],
        ], 200),
    ]);

    $this->artisan('hpanel:agent-check', ['--server' => $this->server->id])
        ->expectsOutputToContain('PANEL_AGENT_HMAC_SECRET')
        ->expectsOutputToContain('Agent check passed successfully.')
        ->assertExitCode(0);
});
