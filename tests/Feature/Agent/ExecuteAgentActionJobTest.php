<?php

use App\Enums\AgentJobStatus;
use App\Jobs\ExecuteAgentAction;
use App\Models\AgentJob;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use App\Services\AuditService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.agent.secret', 'job-test-secret');

    $this->admin = User::factory()->admin()->create();
    $this->server = Server::factory()->create([
        'agent_url' => 'https://10.0.0.1:8443',
    ]);
});

test('ExecuteAgentAction executes successfully and updates job state and logs', function () {
    $job = AgentJob::factory()->create([
        'server_id' => $this->server->id,
        'dispatched_by' => $this->admin->id,
        'action' => 'system.ping',
        'status' => AgentJobStatus::PENDING,
        'payload' => [],
    ]);

    Http::fake([
        'https://10.0.0.1:8443/action' => Http::response([
            'request_id' => $job->id,
            'action' => 'system.ping',
            'success' => true,
            'data' => ['pong' => true, 'timestamp' => 1774000000],
            'logs' => ['system.ping executed successfully', 'all checks passed'],
            'executed_at' => 1774000000,
        ], 200),
    ]);

    $client = new AgentClient;
    $audit = app(AuditService::class);

    $queueJob = new ExecuteAgentAction($job);
    $queueJob->handle($client, $audit);

    $job->refresh();

    expect($job->status)->toBe(AgentJobStatus::COMPLETED)
        ->and($job->result)->toBe(['pong' => true, 'timestamp' => 1774000000])
        ->and($job->completed_at)->not->toBeNull()
        ->and($job->logs)->toHaveCount(4); // Dispatch + 2 agent logs + Completion

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'agent.action_completed',
        'target_type' => AgentJob::class,
        'target_id' => $job->id,
    ]);
});

test('ExecuteAgentAction handles agent failure and records error', function () {
    $job = AgentJob::factory()->create([
        'server_id' => $this->server->id,
        'dispatched_by' => $this->admin->id,
        'action' => 'system.ping',
        'status' => AgentJobStatus::PENDING,
        'payload' => [],
    ]);

    Http::fake([
        'https://10.0.0.1:8443/action' => Http::response([
            'request_id' => $job->id,
            'action' => 'system.ping',
            'success' => false,
            'error' => 'Disk pressure too high to run diagnostics',
            'logs' => ['aborted due to high load'],
        ], 200),
    ]);

    $client = new AgentClient;
    $audit = app(AuditService::class);

    $queueJob = new ExecuteAgentAction($job);
    $queueJob->handle($client, $audit);

    $job->refresh();

    expect($job->status)->toBe(AgentJobStatus::FAILED)
        ->and($job->error_message)->toBe('Disk pressure too high to run diagnostics')
        ->and($job->logs->pluck('message')->toArray())->toContain('aborted due to high load');
});
