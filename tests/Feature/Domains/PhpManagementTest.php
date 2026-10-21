<?php

use App\Enums\DomainType;
use App\Enums\ServerStatus;
use App\Jobs\SwitchDomainPhpVersionJob;
use App\Jobs\WritePhpPoolJob;
use App\Jobs\WriteVhostJob;
use App\Livewire\Domains\PhpManager;
use App\Models\Account;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create(['status' => ServerStatus::ACTIVE, 'private_ip' => '10.0.0.15']);
    $this->package = Package::factory()->create([
        'php_workers' => 8,
        'php_memory_mb' => 256,
        'allowed_php_versions' => ['8.1', '8.2', '8.3', '8.4'],
    ]);

    $this->customer1 = User::factory()->customer()->create(['email' => 'alice@alicecorp.com']);
    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
    ]);
    $this->domain1 = Domain::factory()->create([
        'account_id' => $this->account1->id,
        'domain' => 'alicecorp.com',
        'type' => DomainType::PRIMARY,
        'php_version' => '8.3',
        'document_root' => '/home/alicecorp/public_html',
    ]);

    $this->customer2 = User::factory()->customer()->create();
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobinc',
        'primary_domain' => 'bobinc.com',
    ]);
    $this->domain2 = Domain::factory()->create([
        'account_id' => $this->account2->id,
        'domain' => 'bobinc.com',
        'type' => DomainType::PRIMARY,
        'php_version' => '8.3',
        'document_root' => '/home/bobinc/public_html',
    ]);
});

test('customer can view PHP manager page for their domain', function () {
    $this->actingAs($this->customer1)
        ->get(route('domains.php', $this->domain1))
        ->assertOk()
        ->assertSee('PHP Manager: alicecorp.com')
        ->assertSee('PHP 8.3')
        ->assertSee('8 workers');
});

test('cross-tenant customer is forbidden from accessing another domain PHP manager', function () {
    $this->actingAs($this->customer1)
        ->get(route('domains.php', $this->domain2))
        ->assertForbidden();
});

test('switching php version and valid ini options dispatches switch job', function () {
    Queue::fake();

    Livewire::actingAs($this->customer1)
        ->test(PhpManager::class, ['domain' => $this->domain1])
        ->set('php_version', '8.4')
        ->set('memory_limit', '256M')
        ->set('upload_max_filesize', '64M')
        ->set('post_max_size', '64M')
        ->set('max_execution_time', 120)
        ->set('display_errors', 'off')
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->assertSee('PHP version updated to 8.4');

    Queue::assertPushed(SwitchDomainPhpVersionJob::class, function ($job) {
        return $job->domain->id === $this->domain1->id &&
            $job->newVersion === '8.4' &&
            $job->iniSettings['memory_limit'] === '256M' &&
            $job->iniSettings['max_execution_time'] === 120;
    });
});

test('requesting memory limit exceeding package quota is rejected', function () {
    Queue::fake();

    // Package allowance is 256M, user tries to request 512M or 1024M
    Livewire::actingAs($this->customer1)
        ->test(PhpManager::class, ['domain' => $this->domain1])
        ->set('memory_limit', '512M')
        ->call('saveSettings')
        ->assertHasErrors(['memory_limit']);

    Queue::assertNothingPushed();
});

test('switch domain php version job invokes write php pool job and rewrites vhost', function () {
    Queue::fake([WriteVhostJob::class]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'php.pool_write' &&
                $payload['username'] === 'alicecorp' &&
                $payload['version'] === '8.4' &&
                $payload['max_children'] === 8;
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'username' => 'alicecorp',
                'version' => '8.4',
                'socket' => '/run/php/php8.4-fpm-alicecorp.sock',
            ],
            'logs' => ['Pool written successfully'],
        ]);

    app()->instance(AgentClient::class, $mockClient);

    $job = new SwitchDomainPhpVersionJob($this->domain1, '8.4', [
        'memory_limit' => '256M',
        'upload_max_filesize' => '32M',
    ]);

    $job->handle();

    $this->domain1->refresh();
    expect($this->domain1->php_version)->toEqual('8.4')
        ->and($this->domain1->php_ini['memory_limit'])->toEqual('256M');

    Queue::assertPushed(WriteVhostJob::class, function ($job) {
        return $job->domain->id === $this->domain1->id;
    });
});

test('write php pool job dispatches typed action to agent and logs execution', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'php.pool_write' &&
                $payload['username'] === 'alicecorp' &&
                $payload['version'] === '8.3';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'username' => 'alicecorp',
                'version' => '8.3',
                'socket' => '/run/php/php8.3-fpm-alicecorp.sock',
            ],
            'logs' => ['Configured FPM pool'],
        ]);

    $job = new WritePhpPoolJob($this->account1, '8.3', ['memory_limit' => '128M']);
    $job->handle($mockClient);

    $this->assertDatabaseHas('agent_jobs', [
        'account_id' => $this->account1->id,
        'action' => 'php.pool_write',
        'status' => 'completed',
    ]);
});

test('inspecting fpm worker status updates component state', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'php.fpm_status' && $payload['username'] === 'alicecorp';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'active_workers' => 2,
                'idle_workers' => 3,
                'total_workers' => 5,
                'socket_exists' => true,
            ],
            'logs' => [],
        ]);

    app()->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer1)
        ->test(PhpManager::class, ['domain' => $this->domain1])
        ->call('checkFpmStatus')
        ->assertSet('hasLoadedStatus', true)
        ->assertSee('Total Pool Workers:');
});
