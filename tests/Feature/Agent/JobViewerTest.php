<?php

use App\Enums\AgentJobStatus;
use App\Jobs\ExecuteAgentAction;
use App\Livewire\Admin\JobViewer;
use App\Models\AgentJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->reseller = User::factory()->reseller()->create();
    $this->customer = User::factory()->customer()->create();

    $this->server = Server::factory()->create(['name' => 'Primary Server']);
});

test('non-admins cannot access admin job viewer', function () {
    $this->actingAs($this->reseller)->get('/admin/jobs')->assertStatus(403);
    $this->actingAs($this->customer)->get('/admin/jobs')->assertStatus(403);
});

test('admin can access job viewer page and list jobs', function () {
    $job = AgentJob::factory()->create([
        'server_id' => $this->server->id,
        'action' => 'system.ping',
        'status' => AgentJobStatus::COMPLETED,
    ]);

    $this->actingAs($this->admin)->get('/admin/jobs')->assertStatus(200);

    Livewire::actingAs($this->admin)
        ->test(JobViewer::class)
        ->assertSee('system.ping')
        ->assertSee($this->server->name);
});

test('admin can filter jobs by status and search', function () {
    $completedJob = AgentJob::factory()->create([
        'server_id' => $this->server->id,
        'action' => 'system.ping',
        'status' => AgentJobStatus::COMPLETED,
    ]);

    $failedJob = AgentJob::factory()->create([
        'server_id' => $this->server->id,
        'action' => 'account.create',
        'status' => AgentJobStatus::FAILED,
    ]);

    Livewire::actingAs($this->admin)
        ->test(JobViewer::class)
        ->set('status', 'failed')
        ->assertSee('account.create')
        ->assertDontSee('system.ping')
        ->set('status', '')
        ->set('search', 'account.create')
        ->assertSee('account.create')
        ->assertDontSee('system.ping');
});

test('admin can view job details and logs in modal', function () {
    $job = AgentJob::factory()->create([
        'server_id' => $this->server->id,
        'action' => 'system.health',
        'status' => AgentJobStatus::COMPLETED,
        'payload' => ['metrics' => true],
        'result' => ['cpu' => 4],
    ]);

    $job->recordLog('Health metrics collected successfully', 'info');

    Livewire::actingAs($this->admin)
        ->test(JobViewer::class)
        ->call('viewJob', $job->id)
        ->assertSet('selectedJobId', $job->id)
        ->assertSee('Health metrics collected successfully')
        ->assertSee('system.health')
        ->call('closeModal')
        ->assertSet('selectedJobId', null);
});

test('admin can retry a failed job', function () {
    Queue::fake();

    $failedJob = AgentJob::factory()->failed()->create([
        'server_id' => $this->server->id,
        'action' => 'system.health',
    ]);

    Livewire::actingAs($this->admin)
        ->test(JobViewer::class)
        ->call('retryJob', $failedJob->id)
        ->assertSee('requeued');

    $failedJob->refresh();
    expect($failedJob->status)->toBe(AgentJobStatus::PENDING)
        ->and($failedJob->error_message)->toBeNull();

    Queue::assertPushed(ExecuteAgentAction::class, function ($job) use ($failedJob) {
        return $job->agentJob->id === $failedJob->id;
    });
});

test('admin can dispatch server ping from UI', function () {
    Queue::fake();

    Livewire::actingAs($this->admin)
        ->test(JobViewer::class)
        ->call('dispatchPing', $this->server->id)
        ->assertSee('Ping action dispatched');

    $this->assertDatabaseHas('agent_jobs', [
        'server_id' => $this->server->id,
        'action' => 'system.ping',
        'status' => AgentJobStatus::PENDING->value,
    ]);

    Queue::assertPushed(ExecuteAgentAction::class);
});
