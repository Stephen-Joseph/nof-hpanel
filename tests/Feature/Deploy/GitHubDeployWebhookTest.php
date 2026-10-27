<?php

use App\Livewire\Admin\SystemUpdatesManager;
use App\Models\DeployRun;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->testDir = sys_get_temp_dir() . '/hpanel_deploy_test_' . bin2hex(random_bytes(4));
    @mkdir($this->testDir . '/deploy', 0755, true);
    @mkdir($this->testDir . '/releases', 0755, true);

    $this->triggerPath = $this->testDir . '/deploy/trigger.json';
    $this->currentPath = $this->testDir . '/deploy/current_release.json';
    $this->originPath = $this->testDir . '/deploy/origin_status.json';
    $this->releasesPath = $this->testDir . '/releases';

    config([
        'hpanel.deploy.webhook_secret' => 'super-secret-deploy-token-12345',
        'hpanel.deploy.trigger_path' => $this->triggerPath,
        'hpanel.deploy.current_release_path' => $this->currentPath,
        'hpanel.deploy.origin_status_path' => $this->originPath,
        'hpanel.deploy.releases_path' => $this->releasesPath,
    ]);

    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create();
});

afterEach(function () {
    if (isset($this->testDir) && File::isDirectory($this->testDir)) {
        File::deleteDirectory($this->testDir);
    }
});

test('webhook rejects request with missing signature', function () {
    $payload = ['ref' => 'refs/heads/main'];

    $response = $this->postJson('/internal/deploy/github', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $response->assertStatus(403);
    expect(file_exists($this->triggerPath))->toBeFalse();
    expect(DeployRun::count())->toBe(0);
});

test('webhook rejects request with invalid signature', function () {
    $payload = json_encode(['ref' => 'refs/heads/main']);

    $response = $this->call(
        'POST',
        '/internal/deploy/github',
        [],
        [],
        [],
        [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => 'sha256=invalidhash999999999999999999999999999999999999999999999999999999',
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload
    );

    $response->assertStatus(403);
    expect(file_exists($this->triggerPath))->toBeFalse();
    expect(DeployRun::count())->toBe(0);
});

test('webhook ignores non-push events with 202', function () {
    $payload = json_encode(['zen' => 'Approachable is better than simple.']);
    $secret = config('hpanel.deploy.webhook_secret');
    $sig = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    $response = $this->call(
        'POST',
        '/internal/deploy/github',
        [],
        [],
        [],
        [
            'HTTP_X-GitHub-Event' => 'ping',
            'HTTP_X-Hub-Signature-256' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload
    );

    $response->assertStatus(202);
    $response->assertJsonFragment(['message' => 'Event ignored: ping']);
    expect(file_exists($this->triggerPath))->toBeFalse();
    expect(DeployRun::count())->toBe(0);
});

test('webhook ignores non-main branches with 202', function () {
    $payload = json_encode([
        'ref' => 'refs/heads/feature/awesome-upgrade',
        'after' => '1111222233334444555566667777888899990000',
    ]);
    $secret = config('hpanel.deploy.webhook_secret');
    $sig = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    $response = $this->call(
        'POST',
        '/internal/deploy/github',
        [],
        [],
        [],
        [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload
    );

    $response->assertStatus(202);
    $response->assertJsonFragment(['message' => 'Branch ignored: refs/heads/feature/awesome-upgrade']);
    expect(file_exists($this->triggerPath))->toBeFalse();
    expect(DeployRun::count())->toBe(0);
});

test('webhook accepts valid signature on main branch, writes trigger file, and creates queued deploy_run', function () {
    $targetSha = 'abcdef1234567890abcdef1234567890abcdef12';
    $payload = json_encode([
        'ref' => 'refs/heads/main',
        'after' => $targetSha,
        'head_commit' => [
            'id' => $targetSha,
            'message' => 'feat: implement auto deploy with rollback',
            'author' => [
                'name' => 'Stephen Joseph',
                'username' => 'Stephen-Joseph',
            ],
            'added' => [
                'app/Http/Controllers/Internal/GitHubDeployWebhookController.php',
                'database/migrations/2026_10_27_000004_create_deploy_runs_table.php',
            ],
            'modified' => [],
        ],
    ]);
    $secret = config('hpanel.deploy.webhook_secret');
    $sig = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    $response = $this->call(
        'POST',
        '/internal/deploy/github',
        [],
        [],
        [],
        [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-GitHub-Delivery' => 'test-delivery-id-777',
            'HTTP_X-Hub-Signature-256' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload
    );

    $response->assertStatus(202);
    $response->assertJsonFragment([
        'message' => 'Deployment queued',
        'commit_sha' => $targetSha,
        'status' => 'queued',
    ]);

    // Verify trigger file written atomically
    expect(file_exists($this->triggerPath))->toBeTrue();
    $triggerData = json_decode((string) file_get_contents($this->triggerPath), true);
    expect($triggerData['action'])->toBe('deploy')
        ->and($triggerData['commit_sha'])->toBe($targetSha)
        ->and($triggerData['delivery_id'])->toBe('test-delivery-id-777')
        ->and($triggerData['has_migrations'])->toBeTrue();

    // Verify deploy_runs row created
    $run = DeployRun::first();
    expect($run)->not->toBeNull()
        ->and($run->commit_sha)->toBe($targetSha)
        ->and($run->status)->toBe('queued')
        ->and($run->action)->toBe('deploy')
        ->and($run->has_migrations)->toBeTrue()
        ->and($run->author)->toBe('Stephen Joseph');
});

test('non-admin cannot access updates page', function () {
    $this->actingAs($this->customer)
        ->get('/admin/updates')
        ->assertForbidden();
});

test('admin can view updates page and see current commit, origin status, and deploy history', function () {
    // Write fake current release file
    file_put_contents($this->currentPath, json_encode([
        'commit' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        'message' => 'Production stable release',
        'author' => 'Lead Engineer',
        'deployed_at' => now()->subHours(2)->toIso8601String(),
        'status' => 'success',
    ]));

    // Write fake origin status file
    file_put_contents($this->originPath, json_encode([
        'current_sha' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        'remote_sha' => 'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5',
        'commit_message' => 'New feature with schema change',
        'author' => 'Core Team',
        'up_to_date' => false,
        'has_migrations' => true,
        'last_checked_at' => now()->toIso8601String(),
    ]));

    // Create a previous deploy run
    DeployRun::create([
        'commit_sha' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        'commit_message' => 'Production stable release',
        'author' => 'Lead Engineer',
        'status' => 'success',
        'action' => 'deploy',
        'duration_seconds' => 45,
    ]);

    Livewire::actingAs($this->admin)
        ->test(SystemUpdatesManager::class)
        ->assertOk()
        ->assertSee('System Updates &amp; Auto-Deployment', false)
        ->assertSee('Production stable release')
        ->assertSee('Update Available')
        ->assertSee('Pending Release Contains Database Migrations')
        ->assertSee('Database migrations are additive only')
        ->assertSee('Deploy Now')
        ->assertSee('Rollback to Previous');
});

test('admin deployNow writes deployment trigger file and creates queued deploy run', function () {
    // Write fake origin status file
    file_put_contents($this->originPath, json_encode([
        'current_sha' => '1111111111111111111111111111111111111111',
        'remote_sha' => '2222222222222222222222222222222222222222',
        'commit_message' => 'Deploy now test update',
        'author' => 'Admin User',
        'up_to_date' => false,
        'has_migrations' => false,
        'last_checked_at' => now()->toIso8601String(),
    ]));

    Livewire::actingAs($this->admin)
        ->test(SystemUpdatesManager::class)
        ->call('deployNow');

    expect(file_exists($this->triggerPath))->toBeTrue();
    $triggerData = json_decode((string) file_get_contents($this->triggerPath), true);
    expect($triggerData['action'])->toBe('deploy')
        ->and($triggerData['commit_sha'])->toBe('2222222222222222222222222222222222222222');

    $run = DeployRun::where('commit_sha', '2222222222222222222222222222222222222222')->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('queued')
        ->and($run->action)->toBe('deploy');
});

test('admin rollback writes rollback trigger file and creates queued rollback run', function () {
    $targetSha = '9999999999999999999999999999999999999999';

    Livewire::actingAs($this->admin)
        ->test(SystemUpdatesManager::class)
        ->call('openRollbackModal', $targetSha)
        ->assertSet('showingRollbackModal', true)
        ->assertSet('confirmTargetSha', $targetSha)
        ->call('executeRollback')
        ->assertSet('showingRollbackModal', false);

    expect(file_exists($this->triggerPath))->toBeTrue();
    $triggerData = json_decode((string) file_get_contents($this->triggerPath), true);
    expect($triggerData['action'])->toBe('rollback')
        ->and($triggerData['target_sha'])->toBe($targetSha);

    $run = DeployRun::where('commit_sha', $targetSha)->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('queued')
        ->and($run->action)->toBe('rollback');
});

test('deploy record-status command updates deploy runs and sets log tail', function () {
    $run = DeployRun::create([
        'commit_sha' => '1122334455667788990011223344556677889900',
        'status' => 'queued',
        'action' => 'deploy',
    ]);

    $this->artisan('deploy:record-status', [
        '--run-id' => $run->id,
        '--status' => 'running',
    ])->assertSuccessful();

    expect($run->fresh()->status)->toBe('running')
        ->and($run->fresh()->started_at)->not->toBeNull();

    $this->artisan('deploy:record-status', [
        '--run-id' => $run->id,
        '--status' => 'rolled_back',
        '--step' => 'frontend build',
        '--duration' => 28,
        '--log' => "Line 1\nLine 2\nLine 3",
    ])->assertSuccessful();

    $fresh = $run->fresh();
    expect($fresh->status)->toBe('rolled_back')
        ->and($fresh->failed_step)->toBe('frontend build')
        ->and($fresh->duration_seconds)->toBe(28)
        ->and($fresh->log_tail)->toBe("Line 1\nLine 2\nLine 3")
        ->and($fresh->finished_at)->not->toBeNull();
});
