<?php

use App\Enums\ServerStatus;
use App\Jobs\SyncCronJobsJob;
use App\Livewire\Cron\CronManager;
use App\Models\Account;
use App\Models\CronJob;
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

    $this->package = Package::factory()->create([
        'max_cron_jobs' => 2,
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

test('customer can view their account cron jobs page', function () {
    $this->actingAs($this->customer1)
        ->get(route('accounts.cron', $this->account1))
        ->assertOk()
        ->assertSee('Cron Jobs');
});

test('customer cannot view another accounts cron page', function () {
    $this->actingAs($this->customer1)
        ->get(route('accounts.cron', $this->account2))
        ->assertForbidden();
});

test('admin can view any account cron page', function () {
    $this->actingAs($this->admin)
        ->get(route('accounts.cron', $this->account1))
        ->assertOk();
});

test('customer can create cron job and dispatches sync job', function () {
    Queue::fake();

    Livewire::actingAs($this->customer1)
        ->test(CronManager::class, ['account' => $this->account1])
        ->set('name', 'Artisan Scheduler')
        ->set('preset', 'every_15_minutes')
        ->set('command', '/usr/bin/php /home/alicecorp/artisan schedule:run')
        ->set('emailOutput', 'admin@alicecorp.com')
        ->call('saveJob')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('cron_jobs', [
        'account_id' => $this->account1->id,
        'name' => 'Artisan Scheduler',
        'schedule' => '*/15 * * * *',
        'command' => '/usr/bin/php /home/alicecorp/artisan schedule:run',
        'email_output' => 'admin@alicecorp.com',
        'is_enabled' => true,
    ]);

    Queue::assertPushed(SyncCronJobsJob::class, function ($job) {
        return $job->account->id === $this->account1->id;
    });
});

test('cron creation enforces package limit', function () {
    Queue::fake();

    // Package limit is 2
    CronJob::create([
        'account_id' => $this->account1->id,
        'name' => 'Job 1',
        'schedule' => '0 0 * * *',
        'command' => 'echo 1',
    ]);
    CronJob::create([
        'account_id' => $this->account1->id,
        'name' => 'Job 2',
        'schedule' => '0 1 * * *',
        'command' => 'echo 2',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(CronManager::class, ['account' => $this->account1])
        ->set('name', 'Job 3 (Exceeds Limit)')
        ->set('schedule', '0 2 * * *')
        ->set('command', 'echo 3')
        ->call('saveJob')
        ->assertHasErrors(['name']);

    $this->assertDatabaseMissing('cron_jobs', [
        'name' => 'Job 3 (Exceeds Limit)',
    ]);

    Queue::assertNotPushed(SyncCronJobsJob::class);
});

test('prohibited privilege escalation commands are rejected', function () {
    Queue::fake();

    Livewire::actingAs($this->customer1)
        ->test(CronManager::class, ['account' => $this->account1])
        ->set('name', 'Illegal Sudo')
        ->set('schedule', '* * * * *')
        ->set('command', 'sudo apt-get update')
        ->call('saveJob')
        ->assertHasErrors(['command']);

    Queue::assertNotPushed(SyncCronJobsJob::class);
});

test('customer can toggle cron job status', function () {
    Queue::fake();

    $job = CronJob::create([
        'account_id' => $this->account1->id,
        'name' => 'Nightly Backup',
        'schedule' => '0 2 * * *',
        'command' => '/home/alicecorp/backup.sh',
        'is_enabled' => true,
    ]);

    Livewire::actingAs($this->customer1)
        ->test(CronManager::class, ['account' => $this->account1])
        ->call('toggleJob', $job->id)
        ->assertHasNoErrors();

    $job->refresh();
    $this->assertFalse($job->is_enabled);

    Queue::assertPushed(SyncCronJobsJob::class);
});

test('customer can edit cron job', function () {
    Queue::fake();

    $job = CronJob::create([
        'account_id' => $this->account1->id,
        'name' => 'Old Name',
        'schedule' => '0 0 * * *',
        'command' => 'echo old',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(CronManager::class, ['account' => $this->account1])
        ->call('editJob', $job->id)
        ->set('name', 'Updated Name')
        ->set('command', 'echo new')
        ->call('saveJob')
        ->assertHasNoErrors();

    $job->refresh();
    $this->assertEquals('Updated Name', $job->name);
    $this->assertEquals('echo new', $job->command);

    Queue::assertPushed(SyncCronJobsJob::class);
});

test('customer can delete cron job', function () {
    Queue::fake();

    $job = CronJob::create([
        'account_id' => $this->account1->id,
        'name' => 'To Delete',
        'schedule' => '0 0 * * *',
        'command' => 'echo bye',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(CronManager::class, ['account' => $this->account1])
        ->call('confirmDelete', $job->id)
        ->call('deleteJob')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('cron_jobs', ['id' => $job->id]);

    Queue::assertPushed(SyncCronJobsJob::class);
});

test('customer can view output log of a cron job', function () {
    $job = CronJob::create([
        'account_id' => $this->account1->id,
        'name' => 'Logged Task',
        'schedule' => '*/5 * * * *',
        'command' => 'echo "Task Finished"',
    ]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) use ($job) {
            return $action === 'log.tail' && $payload['log_file'] === $job->logFilename();
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'lines' => ['[2026-10-23 12:00:00] Starting Task', '[2026-10-23 12:00:01] Task Finished'],
            ],
            'logs' => [],
        ]);

    $this->app->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer1)
        ->test(CronManager::class, ['account' => $this->account1])
        ->call('viewOutputLog', $job->id)
        ->assertSet('showingLogModal', true)
        ->assertSee('Task Finished');
});
