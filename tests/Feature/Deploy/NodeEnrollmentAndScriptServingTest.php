<?php

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerEnrollmentToken;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\AgentClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->group = ServerGroup::create([
        'name' => 'Primary Cluster',
        'slug' => 'primary-cluster',
        'location' => 'US-East',
    ]);
});

test('public deploy script route serves install-node.sh and enroll.sh with proper headers', function (string $script) {
    $response = $this->get("/deploy/{$script}");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->getContent())->toContain('#!/usr/bin/env bash');
})->with([
    'install-node.sh',
    'enroll.sh',
]);

test('public deploy script route serves prebuilt panel-agent binary and sha256 checksum', function () {
    $deployDir = public_path('deploy');
    File::ensureDirectoryExists($deployDir);

    $binPath = $deployDir.'/panel-agent-linux-amd64';
    $shaPath = $deployDir.'/panel-agent-linux-amd64.sha256';

    File::put($binPath, 'fake-elf-binary-content-123456');
    $sha = hash_file('sha256', $binPath);
    File::put($shaPath, "{$sha}  panel-agent-linux-amd64\n");

    try {
        $binResp = $this->get('/deploy/panel-agent-linux-amd64');
        $binResp->assertOk();
        $binResp->assertHeader('Content-Type', 'application/octet-stream');
        $binResp->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($binResp->headers->get('Cache-Control'))->toContain('no-store');
        expect($binResp->getContent())->toBe('fake-elf-binary-content-123456');

        $shaResp = $this->get('/deploy/panel-agent-linux-amd64.sha256');
        $shaResp->assertOk();
        $shaResp->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $shaResp->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($shaResp->headers->get('Cache-Control'))->toContain('no-store');
        expect($shaResp->getContent())->toContain($sha);
    } finally {
        @unlink($binPath);
        @unlink($shaPath);
    }
});

test('public deploy script route returns 404 for forbidden or nonexistent script names', function (string $invalidScript) {
    $response = $this->get("/deploy/{$invalidScript}");

    $response->assertNotFound();
})->with([
    'nonexistent.sh',
    'install-panel.sh',
    'backup-panel-db.sh',
    '../.env',
    'update.sh',
]);

test('hpanel:build-agent command builds agent binary with CGO_ENABLED=0 and creates sha256 checksum', function () {
    Process::fake([
        '*' => Process::result(output: 'go build success', exitCode: 0),
    ]);

    $tempOutputDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hpanel-test-build-'.Str::random(8);
    File::ensureDirectoryExists($tempOutputDir);

    try {
        $exitCode = $this->artisan('hpanel:build-agent', [
            '--output-dir' => $tempOutputDir,
            '--target-os' => 'linux',
            '--target-arch' => 'amd64',
        ])->run();

        expect($exitCode)->toBe(0);

        Process::assertRan(function ($process) {
            $env = $process->environment;

            return isset($env['CGO_ENABLED'], $env['GOOS'], $env['GOARCH'])
                && $env['CGO_ENABLED'] === '0'
                && $env['GOOS'] === 'linux'
                && $env['GOARCH'] === 'amd64';
        });

        $binPath = $tempOutputDir.DIRECTORY_SEPARATOR.'panel-agent-linux-amd64';
        $shaPath = $binPath.'.sha256';

        expect(file_exists($binPath))->toBeTrue();
        expect(file_exists($shaPath))->toBeTrue();

        $checksumContent = file_get_contents($shaPath);
        $expectedHash = hash_file('sha256', $binPath);
        expect($checksumContent)->toContain($expectedHash);
        expect($checksumContent)->toContain('panel-agent-linux-amd64');
    } finally {
        File::deleteDirectory($tempOutputDir);
    }
});

test('server enrollment API enforces token single-use, 60-minute expiry, and wrong token 403', function () {
    $rawToken = Str::random(64);

    // 1. Create a token that expires in 60 minutes
    $tokenRecord = ServerEnrollmentToken::create([
        'token_hash' => ServerEnrollmentToken::hashToken($rawToken),
        'server_name' => 'Node-US-01',
        'server_group_id' => $this->group->id,
        'created_by' => $this->admin->id,
        'expires_at' => now()->addMinutes(60),
    ]);

    // 2. Wrong token should return 403 INVALID_TOKEN
    $wrongResp = $this->postJson(route('api.v1.servers.enroll'), [
        'token' => Str::random(64),
        'hostname' => 'node01.example.com',
        'private_ip' => '10.0.10.1',
        'agent_url' => 'https://10.0.10.1:8443',
    ]);
    $wrongResp->assertStatus(403)
        ->assertJson([
            'error' => [
                'code' => 'INVALID_TOKEN',
            ],
        ]);

    // 3. Expired token should return 403 INVALID_TOKEN
    $expiredTokenStr = Str::random(64);
    ServerEnrollmentToken::create([
        'token_hash' => ServerEnrollmentToken::hashToken($expiredTokenStr),
        'server_name' => 'Node-Expired',
        'server_group_id' => $this->group->id,
        'created_by' => $this->admin->id,
        'expires_at' => now()->subMinute(),
    ]);

    $expiredResp = $this->postJson(route('api.v1.servers.enroll'), [
        'token' => $expiredTokenStr,
        'hostname' => 'node-expired.example.com',
        'private_ip' => '10.0.10.2',
        'agent_url' => 'https://10.0.10.2:8443',
    ]);
    $expiredResp->assertStatus(403)
        ->assertJson([
            'error' => [
                'code' => 'INVALID_TOKEN',
            ],
        ]);

    // 4. Valid token succeeds: returns assigned ID, HMAC secret, CA cert, and sets node to ACTIVE on health pass
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('health')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => ['status' => 'ok'],
        ]);
    $this->app->instance(AgentClient::class, $mockClient);

    $enrollResp = $this->postJson(route('api.v1.servers.enroll'), [
        'token' => $rawToken,
        'hostname' => 'node01.example.com',
        'private_ip' => '10.0.10.1',
        'public_ip' => '198.51.100.25',
        'agent_url' => 'https://10.0.10.1:8443',
        'agent_public_cert_fingerprint' => 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
        'cpu_cores' => 4,
        'memory_mb' => 8192,
        'storage_mb' => 102400,
    ]);

    $enrollResp->assertStatus(201)
        ->assertJson([
            'success' => true,
            'server' => [
                'name' => 'Node-US-01',
                'hostname' => 'node01.example.com',
                'private_ip' => '10.0.10.1',
                'public_ip' => '198.51.100.25',
                'status' => 'active',
            ],
        ]);

    $json = $enrollResp->json();
    expect($json['hmac_secret'])->toBeString()->not->toBeEmpty();
    expect($json['server']['id'])->toBeInt();

    // Verify database row
    /** @var Server $server */
    $server = Server::where('hostname', 'node01.example.com')->first();
    expect($server)->not->toBeNull();
    expect($server->agent_public_cert_fingerprint)->toBe('abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890');
    expect($server->public_ip)->toBe('198.51.100.25');
    expect($server->agent_hmac_secret)->toBe($json['hmac_secret']);

    // 5. Token is marked used
    $tokenRecord->refresh();
    expect($tokenRecord->used_at)->not->toBeNull();

    // 6. Token single-use: Reusing the same token fails with 403
    $reuseResp = $this->postJson(route('api.v1.servers.enroll'), [
        'token' => $rawToken,
        'hostname' => 'node02.example.com',
        'private_ip' => '10.0.10.3',
        'agent_url' => 'https://10.0.10.3:8443',
    ]);
    $reuseResp->assertStatus(403)
        ->assertJson([
            'error' => [
                'code' => 'INVALID_TOKEN',
            ],
        ]);
});

test('server enrollment leaves node offline when live agent health check fails', function () {
    $rawToken = Str::random(64);

    ServerEnrollmentToken::create([
        'token_hash' => ServerEnrollmentToken::hashToken($rawToken),
        'server_name' => 'Node-Unreachable',
        'server_group_id' => $this->group->id,
        'created_by' => $this->admin->id,
        'expires_at' => now()->addMinutes(60),
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('health')
        ->once()
        ->andThrow(new Exception('Connection refused'));
    $this->app->instance(AgentClient::class, $mockClient);

    $enrollResp = $this->postJson(route('api.v1.servers.enroll'), [
        'token' => $rawToken,
        'hostname' => 'node-unreachable.example.com',
        'private_ip' => '10.0.10.99',
        'agent_url' => 'https://10.0.10.99:8443',
    ]);

    $enrollResp->assertStatus(201)
        ->assertJson([
            'success' => true,
            'server' => [
                'status' => 'offline',
                'health_check_passed' => false,
            ],
        ]);

    $server = Server::where('hostname', 'node-unreachable.example.com')->first();
    expect($server->status->value)->toBe('offline');
});

test('server health check verification endpoint verifies live agent and marks status active', function () {
    $hmac = bin2hex(random_bytes(32));

    /** @var Server $server */
    $server = Server::create([
        'name' => 'Node-Health-Check',
        'hostname' => 'node-hc.example.com',
        'private_ip' => '10.0.20.1',
        'public_ip' => '198.51.100.30',
        'agent_url' => 'https://10.0.20.1:8443',
        'agent_hmac_secret' => $hmac,
        'status' => ServerStatus::OFFLINE,
        'server_group_id' => $this->group->id,
        'location' => 'US-East',
    ]);

    // 1. Invalid secret returns 403
    $invalidResp = $this->postJson(route('api.v1.servers.verify-health'), [
        'server_id' => $server->id,
        'hmac_secret' => str_repeat('0', 64),
    ]);
    $invalidResp->assertStatus(403)
        ->assertJson([
            'error' => [
                'code' => 'INVALID_CREDENTIALS',
            ],
        ]);

    // 2. Failing health check returns 502 and keeps offline
    $mockFail = Mockery::mock(AgentClient::class);
    $mockFail->shouldReceive('health')
        ->once()
        ->andThrow(new Exception('Timeout connecting to agent'));
    $this->app->instance(AgentClient::class, $mockFail);

    $failResp = $this->postJson(route('api.v1.servers.verify-health'), [
        'server_id' => $server->id,
        'hmac_secret' => $hmac,
    ]);
    $failResp->assertStatus(502)
        ->assertJson([
            'error' => [
                'code' => 'HEALTH_CHECK_FAILED',
            ],
        ]);
    expect($server->fresh()->status->value)->toBe('offline');

    // 3. Successful health check returns 200 and sets status to active
    $mockSuccess = Mockery::mock(AgentClient::class);
    $mockSuccess->shouldReceive('health')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => ['status' => 'healthy'],
        ]);
    $this->app->instance(AgentClient::class, $mockSuccess);

    $okResp = $this->postJson(route('api.v1.servers.verify-health'), [
        'server_id' => $server->id,
        'hmac_secret' => $hmac,
    ]);
    $okResp->assertOk()
        ->assertJson([
            'success' => true,
            'status' => 'active',
        ]);
    expect($server->fresh()->status->value)->toBe('active');
});

test('deploy scripts pass bash syntax check and shellcheck analysis', function (string $script) {
    $scriptPath = base_path("deploy/{$script}");
    expect(file_exists($scriptPath))->toBeTrue();

    // 1. bash -n syntax check
    $bashBin = null;
    foreach (['C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe', '/bin/bash', '/usr/bin/bash'] as $candidate) {
        if (file_exists($candidate)) {
            $bashBin = $candidate;
            break;
        }
    }
    if ($bashBin) {
        $cmd = escapeshellarg($bashBin).' -n '.escapeshellarg($scriptPath);
        exec($cmd.' 2>&1', $output, $code);
        expect($code)->toBe(0, 'bash -n failed: '.implode("\n", $output));
    }

    // 2. shellcheck analysis
    $shellcheckBin = null;
    foreach (['C:\\CLI-Tools\\bin\\shellcheck.exe', 'shellcheck', '/usr/bin/shellcheck', '/usr/local/bin/shellcheck'] as $candidate) {
        if (file_exists($candidate)) {
            $shellcheckBin = $candidate;
            break;
        }
    }
    if ($shellcheckBin) {
        $cmd = escapeshellarg($shellcheckBin).' '.escapeshellarg($scriptPath);
        exec($cmd.' 2>&1', $scOutput, $scCode);
        expect($scCode)->toBe(0, 'shellcheck failed: '.implode("\n", $scOutput));
    }
})->with([
    'enroll.sh',
    'install-node.sh',
    'update.sh',
]);

test('deploy scripts dry-run prints which packages would be installed', function (string $script, array $args) {
    $bashBin = null;
    foreach (['C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe', '/bin/bash', '/usr/bin/bash'] as $candidate) {
        if (file_exists($candidate)) {
            $bashBin = $candidate;
            break;
        }
    }

    if (! $bashBin) {
        $this->markTestSkipped('Bash is not installed in the execution environment.');
    }

    $scriptPath = base_path("deploy/{$script}");
    $cmd = escapeshellarg($bashBin).' '.escapeshellarg($scriptPath).' '.implode(' ', array_map('escapeshellarg', $args));

    exec($cmd.' 2>&1', $output, $code);
    $outText = implode("\n", $output);

    expect($code)->toBe(0, "Script exited with {$code}: {$outText}");
    expect($outText)->toContain('[DRY-RUN]');
    expect($outText)->toMatch('/Would install missing prerequisites|prerequisites/i');
})->with([
    'enroll.sh' => ['enroll.sh', ['--dry-run', '--token', str_repeat('a', 64), '--panel', 'https://panel.example.com']],
    'install-node.sh' => ['install-node.sh', ['--dry-run', '--token', str_repeat('b', 64), '--panel', 'https://panel.example.com']],
]);
