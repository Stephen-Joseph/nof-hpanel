<?php

use App\Enums\AppProfile;
use App\Enums\DomainType;
use App\Enums\ServerStatus;
use App\Jobs\SwitchDomainPhpVersionJob;
use App\Livewire\Domains\PhpManager;
use App\Models\Account;
use App\Models\AgentJob;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create(['status' => ServerStatus::ACTIVE]);
    $this->package = Package::factory()->create([
        'allowed_php_versions' => ['8.1', '8.2', '8.3', '8.4'],
        'php_workers' => 5,
        'php_memory_mb' => 256,
    ]);
    $this->owner = User::factory()->customer()->create();
    $this->otherCustomer = User::factory()->customer()->create();
    $this->account = Account::factory()->create([
        'user_id' => $this->owner->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'laraveluser',
        'primary_domain' => 'laravelapp.test',
    ]);
    $this->domain = Domain::factory()->create([
        'account_id' => $this->account->id,
        'domain' => 'laravelapp.test',
        'type' => DomainType::PRIMARY,
        'is_primary' => true,
        'app_profile' => AppProfile::LARAVEL,
        'php_version' => '8.2',
        'document_root' => '/home/laraveluser/public_html/public',
    ]);
});

it('creates one job with all 7 listed steps when switching PHP version', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($srv, $action) => $action === 'php.set_version')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'domain' => 'laravelapp.test',
                'version' => '8.4',
                'document_root' => '/home/laraveluser/public_html/public',
            ],
            'logs' => ['Switched to PHP 8.4'],
        ]);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($srv, $action) => $action === 'laravel.fix_permissions')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => ['laravel_root' => '/home/laraveluser/public_html'],
            'logs' => ['Permissions fixed'],
        ]);

    $job = new SwitchDomainPhpVersionJob($this->domain, '8.4', ['memory_limit' => '256M']);
    $job->handle($mockClient);

    $agentJobs = AgentJob::where('account_id', $this->account->id)->where('action', 'php.set_version')->get();
    expect($agentJobs)->toHaveCount(1);

    $steps = $agentJobs->first()->provisioningSteps()->orderBy('id')->pluck('key')->all();
    expect($steps)->toBe([
        'create_pool',
        'test_pool',
        'reload_fpm',
        'update_vhost',
        'test_nginx',
        'reload_nginx',
        'fix_permissions',
    ]);

    expect($this->domain->fresh()->php_version)->toBe('8.4');
});

it('shows warning when PHP version is below composer requirement and allows switching', function () {
    Queue::fake();

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($srv, $action) => $action === 'php.list_versions')
        ->andReturn([
            'success' => true,
            'data' => ['installed_versions' => ['8.2', '8.3', '8.4']],
            'logs' => [],
        ]);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($srv, $action) => $action === 'laravel.detect')
        ->andReturn([
            'success' => true,
            'data' => [
                'detected' => true,
                'laravel_root' => '/home/laraveluser/public_html',
                'min_php_version' => '8.4',
            ],
            'logs' => [],
        ]);
    $this->app->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->owner)
        ->test(PhpManager::class, ['domain' => $this->domain])
        ->assertSee('PHP Version Warning')
        ->assertSee('Switch to 8.4')
        ->call('switchToRequiredVersion')
        ->assertSet('php_version', '8.4');

    Queue::assertPushed(SwitchDomainPhpVersionJob::class, function ($job) {
        return $job->domain->id === $this->domain->id && $job->newVersion === '8.4';
    });
});

it('returns 403 for cross-tenant PHP manager access and mutations', function () {
    $this->actingAs($this->otherCustomer)
        ->get(route('domains.php', $this->domain))
        ->assertForbidden();

    Livewire::actingAs($this->otherCustomer)
        ->test(PhpManager::class, ['domain' => $this->domain])
        ->assertForbidden();
});
