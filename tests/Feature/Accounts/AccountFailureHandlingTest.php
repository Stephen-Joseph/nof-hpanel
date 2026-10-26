<?php

use App\Enums\AccountStatus;
use App\Enums\AgentJobStatus;
use App\Jobs\ExecuteAgentAction;
use App\Jobs\ProvisionAccount;
use App\Jobs\TerminateAccount;
use App\Livewire\Admin\JobViewer;
use App\Models\Account;
use App\Models\AgentJob;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use App\Services\AuditService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('services.agent.secret', 'test-secret-12345');

    $this->server = Server::factory()->create([
        'agent_url' => 'https://127.0.0.1:8443',
    ]);
    $this->admin = User::factory()->admin()->create();
    $this->user = User::factory()->customer()->create();
    $this->package = Package::factory()->create();

    $this->account = Account::factory()->pending()->create([
        'user_id' => $this->user->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'failtest1',
        'primary_domain' => 'failtest1.example',
    ]);

    $this->client = new AgentClient;
    $this->audit = app(AuditService::class);
});

test('failed provisioning transitions account from PENDING to FAILED with failure_reason and no DomainException', function () {
    Http::fake([
        'https://127.0.0.1:8443/action' => Http::response([
            'request_id' => 'req-fail-1',
            'action' => 'account.create',
            'success' => false,
            'error' => [
                'code' => 'STEP_FAILED',
                'message' => 'Quota initialization failed',
                'details' => 'Filesystem error',
            ],
            'logs' => ['failed at step quota'],
        ], 200),
    ]);

    $job = new ProvisionAccount($this->account);
    $job->handle($this->client, $this->audit);

    $this->account->refresh();
    expect($this->account->status)->toBe(AccountStatus::FAILED)
        ->and($this->account->failure_reason)->toContain('Quota initialization failed')
        ->and($this->account->suspended_reason)->toBeNull();
});

test('terminating a PENDING account transitions to FAILED and returns without throwing DomainException', function () {
    $job = new TerminateAccount($this->account);
    $job->handle($this->client, $this->audit);

    $this->account->refresh();
    expect($this->account->status)->toBe(AccountStatus::FAILED)
        ->and($this->account->failure_reason)->toBe('Terminated while pending');
});

test('audit logging failure does not fail the AgentJob and keeps it completed', function () {
    Http::fake([
        'https://127.0.0.1:8443/action' => Http::response([
            'request_id' => 'req-audit-throw',
            'action' => 'system.ping',
            'success' => true,
            'data' => ['pong' => true],
            'logs' => ['ping ok'],
        ], 200),
    ]);

    $agentJob = AgentJob::create([
        'server_id' => $this->server->id,
        'dispatched_by' => $this->admin->id,
        'action' => 'system.ping',
        'status' => AgentJobStatus::PENDING,
        'payload' => [],
        'attempts' => 0,
        'max_attempts' => 3,
    ]);

    $mockAudit = Mockery::mock(AuditService::class);
    $mockAudit->shouldReceive('log')
        ->andThrow(new \RuntimeException('Database disk full while logging audit'));

    $job = new ExecuteAgentAction($agentJob);
    $job->handle($this->client, $mockAudit);

    $agentJob->refresh();
    expect($agentJob->status)->toBe(AgentJobStatus::COMPLETED)
        ->and($agentJob->attempts)->toBe(1)
        ->and($agentJob->error_message)->toBeNull();
});

test('AgentJob attempts never exceeds max_attempts and manual retry resets attempts to 0', function () {
    $job = AgentJob::create([
        'server_id' => $this->server->id,
        'dispatched_by' => $this->admin->id,
        'action' => 'system.ping',
        'status' => AgentJobStatus::PENDING,
        'payload' => [],
        'attempts' => 0,
        'max_attempts' => 3,
    ]);

    // Multiple markRunning calls should never push attempts above max_attempts
    $job->markRunning();
    expect($job->attempts)->toBe(1);

    $job->markRunning();
    expect($job->attempts)->toBe(2);

    $job->markRunning();
    expect($job->attempts)->toBe(3);

    $job->markRunning();
    expect($job->attempts)->toBe(3);

    // Explicitly setting attempts above max_attempts is capped by mutator
    $job->attempts = 10;
    $job->save();
    $job->refresh();
    expect($job->attempts)->toBe(3);

    // Manual retry in JobViewer resets attempts to 0
    \Illuminate\Support\Facades\Queue::fake([ExecuteAgentAction::class]);
    $this->actingAs($this->admin);
    Livewire::test(JobViewer::class)
        ->call('retryJob', $job->id);

    \Illuminate\Support\Facades\Queue::assertPushed(ExecuteAgentAction::class);

    $job->refresh();
    expect($job->attempts)->toBe(0)
        ->and($job->status)->toBe(AgentJobStatus::PENDING)
        ->and($job->error_message)->toBeNull();

    // Verify log entry was recorded
    expect($job->logs()->where('message', 'like', '%Job retry manually triggered%')->exists())->toBeTrue();
});
