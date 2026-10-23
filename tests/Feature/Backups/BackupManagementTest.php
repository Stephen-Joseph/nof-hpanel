<?php

use App\Enums\ServerStatus;
use App\Jobs\CreateBackupJob;
use App\Jobs\DeleteBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Livewire\Backups\BackupManager;
use App\Models\Account;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Services\AgentClient;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'status' => ServerStatus::ACTIVE,
        'hostname' => 'node1.example.com',
        'private_ip' => '10.0.0.10',
    ]);

    $this->package = Package::factory()->create([
        'backups_retained' => 5,
    ]);

    $this->customer1 = User::factory()->customer()->create();
    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
    ]);

    $this->customer2 = User::factory()->customer()->create();
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobinc',
        'primary_domain' => 'bobinc.com',
    ]);

    $this->admin = User::factory()->admin()->create();
});

test('customer can view their account backups page', function () {
    $this->actingAs($this->customer1)
        ->get(route('accounts.backups', $this->account1))
        ->assertOk()
        ->assertSee('Backups &amp; Snapshots', false);
});

test('customer cannot view another accounts backups', function () {
    $this->actingAs($this->customer1)
        ->get(route('accounts.backups', $this->account2))
        ->assertForbidden();
});

test('admin can view any account backups page', function () {
    $this->actingAs($this->admin)
        ->get(route('accounts.backups', $this->account1))
        ->assertOk();
});

test('customer can initiate manual backup and dispatches job', function () {
    Queue::fake();

    Livewire::actingAs($this->customer1)
        ->test(BackupManager::class, ['account' => $this->account1])
        ->call('createManualBackup')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('backups', [
        'account_id' => $this->account1->id,
        'backup_type' => 'manual',
        'status' => 'pending',
    ]);

    Queue::assertPushed(CreateBackupJob::class);
});

test('customer cannot create manual backup if one is already in progress', function () {
    Queue::fake();

    Backup::create([
        'account_id' => $this->account1->id,
        'backup_type' => 'manual',
        'status' => 'running',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(BackupManager::class, ['account' => $this->account1])
        ->call('createManualBackup')
        ->assertHasErrors(['manual']);

    Queue::assertNotPushed(CreateBackupJob::class);
});

test('CreateBackupJob executes successfully, records snapshot, and dispatches webhook', function () {
    Http::fake();

    $subscription = WebhookSubscription::create([
        'user_id' => $this->customer1->id,
        'name' => 'Backup Webhook',
        'url' => 'https://example.com/webhooks',
        'secret' => 'webhook-secret-key-1234',
        'events' => ['backup.completed', 'backup.failed'],
        'is_active' => true,
    ]);

    $backup = Backup::create([
        'account_id' => $this->account1->id,
        'backup_type' => 'manual',
        'status' => 'pending',
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'backup.create' && $payload['username'] === 'alicecorp';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'snapshot_id' => 'a1b2c3d4e5f6',
                'bytes_added' => 10485760,
                'file_count' => 120,
                'duration_seconds' => 4.25,
            ],
            'logs' => ['Snapshot created successfully'],
        ]);

    $this->app->instance(AgentClient::class, $mockClient);

    $job = new CreateBackupJob($backup);
    $job->handle($mockClient, app(WebhookService::class));

    $backup->refresh();
    $this->assertEquals('completed', $backup->status);
    $this->assertEquals('a1b2c3d4e5f6', $backup->snapshot_id);
    $this->assertEquals(10485760, $backup->size_bytes);
    $this->assertEquals(120, $backup->file_count);
    $this->assertNotNull($backup->completed_at);

    // Verify webhook delivery
    $this->assertDatabaseHas('webhook_deliveries', [
        'webhook_subscription_id' => $subscription->id,
        'event' => 'backup.completed',
    ]);
});

test('customer can save automated backup schedule policy', function () {
    Livewire::actingAs($this->customer1)
        ->test(BackupManager::class, ['account' => $this->account1])
        ->set('scheduleFrequency', 'weekly')
        ->set('scheduleTime', '03:30')
        ->set('scheduleDayOfWeek', 3) // Wednesday
        ->set('scheduleEnabled', true)
        ->call('saveSchedule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('backup_schedules', [
        'account_id' => $this->account1->id,
        'frequency' => 'weekly',
        'time_of_day' => '03:30',
        'day_of_week' => 3,
        'is_enabled' => true,
    ]);

    $schedule = BackupSchedule::where('account_id', $this->account1->id)->first();
    $this->assertNotNull($schedule->next_run_at);
});

test('artisan command backups:run-scheduled triggers due backups', function () {
    Queue::fake();

    BackupSchedule::create([
        'account_id' => $this->account1->id,
        'frequency' => 'daily',
        'time_of_day' => '02:00',
        'is_enabled' => true,
        'next_run_at' => now()->subMinutes(10), // due
    ]);

    $this->artisan('backups:run-scheduled')
        ->assertSuccessful();

    $this->assertDatabaseHas('backups', [
        'account_id' => $this->account1->id,
        'backup_type' => 'scheduled',
    ]);

    Queue::assertPushed(CreateBackupJob::class);

    $schedule = BackupSchedule::where('account_id', $this->account1->id)->first();
    $this->assertTrue($schedule->next_run_at->isFuture());
});

test('customer can execute restore wizard', function () {
    Queue::fake();

    $backup = Backup::create([
        'account_id' => $this->account1->id,
        'snapshot_id' => 'a1b2c3d4e5f6',
        'backup_type' => 'manual',
        'status' => 'completed',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(BackupManager::class, ['account' => $this->account1])
        ->call('openRestoreWizard', $backup->id)
        ->set('restoreType', 'files_only')
        ->call('executeRestore')
        ->assertHasNoErrors();

    Queue::assertPushed(RestoreBackupJob::class, function ($job) use ($backup) {
        return $job->account->id === $this->account1->id
            && $job->snapshotId === $backup->snapshot_id
            && $job->restoreType === 'files_only';
    });
});

test('customer can delete backup', function () {
    Queue::fake();

    $backup = Backup::create([
        'account_id' => $this->account1->id,
        'snapshot_id' => 'a1b2c3d4e5f6',
        'backup_type' => 'manual',
        'status' => 'completed',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(BackupManager::class, ['account' => $this->account1])
        ->call('confirmDelete', $backup->id)
        ->call('deleteBackup')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('backups', ['id' => $backup->id]);

    Queue::assertPushed(DeleteBackupJob::class, function ($job) use ($backup) {
        return $job->snapshotId === $backup->snapshot_id;
    });
});
