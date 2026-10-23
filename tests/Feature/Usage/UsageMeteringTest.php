<?php

use App\Enums\AccountStatus;
use App\Enums\ServerStatus;
use App\Jobs\CollectAccountUsageJob;
use App\Jobs\DispatchWebhookJob;
use App\Livewire\Accounts\UsageDashboard;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Services\AgentClient;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'status' => ServerStatus::ACTIVE,
        'hostname' => 'node1.example.com',
        'private_ip' => '10.0.0.10',
    ]);

    $this->package = Package::factory()->create([
        'storage_mb' => 1000,
        'inodes' => 10000,
        'bandwidth_gb' => 10,
    ]);

    $this->customer = User::factory()->customer()->create();
    $this->account = Account::factory()->create([
        'user_id' => $this->customer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
        'status' => AccountStatus::ACTIVE,
    ]);

    $this->otherCustomer = User::factory()->customer()->create();
    $this->otherAccount = Account::factory()->create([
        'user_id' => $this->otherCustomer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobcorp',
        'primary_domain' => 'bobcorp.com',
        'status' => AccountStatus::ACTIVE,
    ]);

    $this->admin = User::factory()->admin()->create();
});

test('customer can view their usage dashboard', function () {
    $this->actingAs($this->customer)
        ->get(route('accounts.usage', $this->account))
        ->assertOk()
        ->assertSee('Resource Usage');
});

test('customer cannot view another accounts usage dashboard', function () {
    $this->actingAs($this->customer)
        ->get(route('accounts.usage', $this->otherAccount))
        ->assertForbidden();
});

test('admin can view cross-account usage overview', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.usage.index'))
        ->assertOk()
        ->assertSee('System Resource Metering & Quotas');
});

test('customer cannot access admin usage overview', function () {
    $this->actingAs($this->customer)
        ->get(route('admin.usage.index'))
        ->assertForbidden();
});

test('customer can trigger manual poll now on usage dashboard', function () {
    Queue::fake();

    Livewire::actingAs($this->customer)
        ->test(UsageDashboard::class, ['account' => $this->account])
        ->call('pollNow')
        ->assertHasNoErrors();

    Queue::assertPushed(CollectAccountUsageJob::class, function ($job) {
        return $job->account->id === $this->account->id;
    });
});

test('collect account usage job polls agent, stores snapshot, and updates account', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::any(),
            'usage.collect',
            Mockery::on(function ($payload) {
                return $payload['username'] === 'alicecorp';
            }),
            Mockery::any()
        )
        ->andReturn([
            'success' => true,
            'data' => [
                'storage_mb' => 350.5,
                'inodes' => 2450,
                'bandwidth_bytes_total' => 104857600, // 100 MB
                'database_bytes_total' => 52428800,  // 50 MB
                'fpm_active_workers' => 2,
                'fpm_idle_workers' => 3,
                'fpm_total_workers' => 5,
                'new_log_offsets' => ['alicecorp.com' => 1048576],
            ],
            'error' => null,
            'logs' => ['collected metrics'],
            'request_id' => 'req-usage-1',
            'action' => 'usage.collect',
        ]);

    $webhookService = app(WebhookService::class);
    $job = new CollectAccountUsageJob($this->account);
    $job->handle($mockClient, $webhookService);

    $this->assertDatabaseHas('usage_snapshots', [
        'account_id' => $this->account->id,
        'storage_mb' => 350.5,
        'inodes' => 2450,
        'bandwidth_bytes' => 104857600,
    ]);

    $this->account->refresh();
    expect($this->account->current_storage_mb)->toBe(350.5)
        ->and($this->account->current_inodes)->toBe(2450)
        ->and($this->account->current_bandwidth_bytes)->toBe(104857600)
        ->and($this->account->log_offsets)->toHaveKey('alicecorp.com');
});

test('quota threshold enforcement triggers webhooks at 80% and 100%', function () {
    Queue::fake([DispatchWebhookJob::class]);

    $sub = WebhookSubscription::create([
        'user_id' => $this->customer->id,
        'url' => 'https://example.com/webhook',
        'secret' => 'supersecret',
        'events' => ['usage.warning', 'usage.limit_reached'],
        'is_active' => true,
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    // Return 850 MB on a 1000 MB package (85% usage -> triggers tier 80 warning)
    $mockClient->shouldReceive('execute')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'storage_mb' => 850.0,
                'inodes' => 1000,
                'bandwidth_bytes_total' => 0,
                'database_bytes_total' => 0,
                'fpm_active_workers' => 0,
                'fpm_total_workers' => 0,
                'new_log_offsets' => [],
            ],
            'error' => null,
            'logs' => [],
            'request_id' => 'req-1',
            'action' => 'usage.collect',
        ]);

    $webhookService = app(WebhookService::class);
    $job = new CollectAccountUsageJob($this->account);
    $job->handle($mockClient, $webhookService);

    $this->account->refresh();
    expect($this->account->usage_warning_level)->toBe(80);

    Queue::assertPushed(DispatchWebhookJob::class, function ($job) use ($sub) {
        return $job->subscription->id === $sub->id && $job->event === 'usage.warning';
    });

    // Next pass: exceed 100% limit (1050 MB)
    $mockClient2 = Mockery::mock(AgentClient::class);
    $mockClient2->shouldReceive('execute')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'storage_mb' => 1050.0,
                'inodes' => 1000,
                'bandwidth_bytes_total' => 0,
                'database_bytes_total' => 0,
                'fpm_active_workers' => 0,
                'fpm_total_workers' => 0,
                'new_log_offsets' => [],
            ],
            'error' => null,
            'logs' => [],
            'request_id' => 'req-2',
            'action' => 'usage.collect',
        ]);

    $job2 = new CollectAccountUsageJob($this->account);
    $job2->handle($mockClient2, $webhookService);

    $this->account->refresh();
    expect($this->account->usage_warning_level)->toBe(100)
        ->and($this->account->is_throttled)->toBeTrue();

    Queue::assertPushed(DispatchWebhookJob::class, function ($job) use ($sub) {
        return $job->subscription->id === $sub->id && $job->event === 'usage.limit_reached';
    });
});

test('artisan usage:collect command dispatches jobs for active accounts', function () {
    Queue::fake();

    Artisan::call('usage:collect');

    Queue::assertPushed(CollectAccountUsageJob::class, 2);
});

test('artisan bandwidth:reset command resets bandwidth counters and restores throttling', function () {
    $this->account->update([
        'current_bandwidth_bytes' => 5368709120, // 5 GB
        'is_throttled' => true,
        'usage_warning_level' => 100,
    ]);

    Artisan::call('bandwidth:reset');

    $this->account->refresh();
    expect($this->account->current_bandwidth_bytes)->toBe(0)
        ->and($this->account->is_throttled)->toBeFalse()
        ->and($this->account->usage_warning_level)->toBe(0)
        ->and($this->account->bandwidth_reset_at)->not->toBeNull();
});

test('api v1 accounts usage endpoint returns live telemetry and limits', function () {
    $this->account->update([
        'current_storage_mb' => 450.5,
        'current_inodes' => 3200,
        'current_bandwidth_bytes' => 1073741824, // 1 GB
        'usage_warning_level' => 0,
    ]);

    $token = ApiToken::create([
        'user_id' => $this->customer->id,
        'name' => 'Test Token',
        'token_hash' => hash('sha256', 'plain_test_token_123'),
        'scopes' => ['accounts:read'],
    ]);

    $response = $this->withHeader('Authorization', 'Bearer plain_test_token_123')
        ->getJson("/api/v1/accounts/{$this->account->id}/usage");

    $response->assertOk()
        ->assertJson([
            'account_id' => $this->account->id,
            'username' => 'alicecorp',
            'is_throttled' => false,
            'usage' => [
                'storage_mb' => [
                    'used' => 450.5,
                    'limit' => 1000,
                    'percentage' => 45.1,
                ],
                'inodes' => [
                    'used' => 3200,
                    'limit' => 10000,
                    'percentage' => 32.0,
                ],
                'bandwidth_gb' => [
                    'used' => 1.0,
                    'limit' => 10,
                    'percentage' => 10.0,
                ],
            ],
        ]);
});
