<?php

use App\Enums\AccountStatus;
use App\Enums\ServerStatus;
use App\Jobs\ExecuteGitDeployJob;
use App\Jobs\RollbackGitDeployJob;
use App\Livewire\Git\GitDeploymentManager;
use App\Models\Account;
use App\Models\GitDeployment;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'status' => ServerStatus::ACTIVE,
        'hostname' => 'node1.example.com',
        'private_ip' => '10.0.0.10',
    ]);

    $this->packageWithGit = Package::factory()->create([
        'git_enabled' => true,
    ]);

    $this->packageWithoutGit = Package::factory()->create([
        'git_enabled' => false,
    ]);

    $this->customer = User::factory()->customer()->create();
    $this->account = Account::factory()->create([
        'user_id' => $this->customer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->packageWithGit->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
        'status' => AccountStatus::ACTIVE,
    ]);

    $this->otherCustomer = User::factory()->customer()->create();
    $this->otherAccount = Account::factory()->create([
        'user_id' => $this->otherCustomer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->packageWithGit->id,
        'linux_username' => 'bobcorp',
        'primary_domain' => 'bobcorp.com',
    ]);
});

test('customer can view their git deployments page', function () {
    $this->actingAs($this->customer)
        ->get(route('accounts.git', $this->account))
        ->assertOk()
        ->assertSee('Git Deployments');
});

test('customer cannot view another accounts git deployments page', function () {
    $this->actingAs($this->customer)
        ->get(route('accounts.git', $this->otherAccount))
        ->assertForbidden();
});

test('git deployment creation requires git_enabled on package', function () {
    $restrictedAccount = Account::factory()->create([
        'user_id' => $this->customer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->packageWithoutGit->id,
        'linux_username' => 'restricteduser',
    ]);

    Livewire::actingAs($this->customer)
        ->test(GitDeploymentManager::class, ['account' => $restrictedAccount])
        ->call('openCreateModal')
        ->assertSee('Git deployment is not enabled on this hosting package.');
});

test('customer can create git deployment and generate deploy key', function () {
    Livewire::actingAs($this->customer)
        ->test(GitDeploymentManager::class, ['account' => $this->account])
        ->set('name', 'Production App')
        ->set('repository_url', 'git@github.com:example/app.git')
        ->set('branch', 'main')
        ->set('deploy_path', '/home/alicecorp/public_html')
        ->set('build_steps', ['composer_install', 'artisan_migrate'])
        ->set('auto_deploy', true)
        ->call('createDeployment')
        ->assertHasNoErrors()
        ->assertSet('showKeyModal', true);

    $this->assertDatabaseHas('git_deployments', [
        'account_id' => $this->account->id,
        'name' => 'Production App',
        'branch' => 'main',
        'deploy_path' => '/home/alicecorp/public_html',
    ]);

    /** @var GitDeployment $dep */
    $dep = GitDeployment::where('name', 'Production App')->firstOrFail();
    expect($dep->public_key)->toContain('ssh-rsa')
        ->and($dep->private_key)->not->toBeEmpty()
        ->and($dep->webhook_secret)->not->toBeEmpty();
});

test('customer can trigger deployment manually', function () {
    Queue::fake();

    /** @var GitDeployment $dep */
    $dep = $this->account->gitDeployments()->create([
        'name' => 'Staging App',
        'repository_url' => 'https://github.com/example/staging.git',
        'branch' => 'staging',
        'deploy_path' => '/home/alicecorp/apps/staging',
        'public_key' => 'ssh-rsa AAAAB3...',
        'private_key' => 'dummy_priv_key',
        'webhook_secret' => 'secret_123',
    ]);

    Livewire::actingAs($this->customer)
        ->test(GitDeploymentManager::class, ['account' => $this->account])
        ->call('deployNow', $dep->id);

    Queue::assertPushed(ExecuteGitDeployJob::class, function ($job) use ($dep) {
        return $job->deployment->id === $dep->id;
    });
});

test('execute git deploy job runs agent action and updates release status', function () {
    /** @var GitDeployment $dep */
    $dep = $this->account->gitDeployments()->create([
        'name' => 'Production App',
        'repository_url' => 'git@github.com:example/prod.git',
        'branch' => 'main',
        'deploy_path' => '/home/alicecorp/public_html',
        'public_key' => 'ssh-rsa AAAAB3...',
        'private_key' => 'dummy_priv_key',
        'webhook_secret' => 'secret_prod',
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::any(),
            'git.deploy',
            Mockery::on(function ($payload) {
                return $payload['username'] === 'alicecorp'
                    && $payload['branch'] === 'main';
            }),
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn([
            'success' => true,
            'data' => [
                'release_tag' => '20261021120000',
                'commit_hash' => 'a1b2c3d4e5f6',
                'commit_message' => 'Initial deploy',
                'committer' => 'Alice Admin',
                'duration_seconds' => 3.45,
            ],
            'error' => null,
            'logs' => ['cloned main branch', 'atomic symlink updated'],
            'request_id' => 'req-1',
            'action' => 'git.deploy',
        ]);

    $job = new ExecuteGitDeployJob($dep, '20261021120000');
    $job->handle($mockClient);

    $this->assertDatabaseHas('git_releases', [
        'git_deployment_id' => $dep->id,
        'release_tag' => '20261021120000',
        'status' => 'success',
        'commit_hash' => 'a1b2c3d4e5f6',
    ]);

    $dep->refresh();
    expect($dep->last_commit_hash)->toBe('a1b2c3d4e5f6');
});

test('rollback git deploy job executes rollback action', function () {
    /** @var GitDeployment $dep */
    $dep = $this->account->gitDeployments()->create([
        'name' => 'Rollback App',
        'repository_url' => 'git@github.com:example/rollback.git',
        'branch' => 'main',
        'deploy_path' => '/home/alicecorp/public_html',
        'public_key' => 'ssh-rsa AAAAB3...',
        'private_key' => 'dummy_priv_key',
        'webhook_secret' => 'secret_rb',
    ]);

    $dep->releases()->create([
        'release_tag' => '20261020100000',
        'status' => 'success',
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::any(),
            'git.rollback',
            Mockery::on(function ($payload) {
                return $payload['target_release_tag'] === '20261020100000';
            }),
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn([
            'success' => true,
            'data' => ['active_release' => '20261020100000', 'status' => 'rolled_back'],
            'error' => null,
            'logs' => ['swapped symlink to 20261020100000'],
            'request_id' => 'req-rb',
            'action' => 'git.rollback',
        ]);

    $job = new RollbackGitDeployJob($dep, '20261020100000');
    $job->handle($mockClient);
});

test('webhook endpoint queues git deployment when valid secret is posted', function () {
    Queue::fake();

    /** @var GitDeployment $dep */
    $dep = $this->account->gitDeployments()->create([
        'name' => 'Auto Webhook App',
        'repository_url' => 'git@github.com:example/hook.git',
        'branch' => 'main',
        'deploy_path' => '/home/alicecorp/public_html',
        'public_key' => 'ssh-rsa AAAAB3...',
        'private_key' => 'dummy_priv_key',
        'webhook_secret' => 'valid_webhook_secret_123',
        'auto_deploy' => true,
    ]);

    $response = $this->postJson(route('api.v1.git-deploy', ['secret' => 'valid_webhook_secret_123']));
    $response->assertStatus(202)
        ->assertJson([
            'success' => true,
            'message' => 'Deployment job queued successfully.',
        ]);

    Queue::assertPushed(ExecuteGitDeployJob::class, function ($job) use ($dep) {
        return $job->deployment->id === $dep->id;
    });
});

test('webhook endpoint ignores request if auto_deploy is disabled', function () {
    Queue::fake();

    $this->account->gitDeployments()->create([
        'name' => 'Manual App',
        'repository_url' => 'git@github.com:example/manual.git',
        'branch' => 'main',
        'deploy_path' => '/home/alicecorp/public_html',
        'public_key' => 'ssh-rsa AAAAB3...',
        'private_key' => 'dummy_priv_key',
        'webhook_secret' => 'manual_webhook_secret',
        'auto_deploy' => false,
    ]);

    $response = $this->postJson(route('api.v1.git-deploy', ['secret' => 'manual_webhook_secret']));
    $response->assertStatus(200)
        ->assertJson([
            'status' => 'ignored',
        ]);

    Queue::assertNotPushed(ExecuteGitDeployJob::class);
});

test('webhook endpoint returns 404 for unknown secret', function () {
    $response = $this->postJson(route('api.v1.git-deploy', ['secret' => 'nonexistent_secret']));
    $response->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'NOT_FOUND',
            ],
        ]);
});
