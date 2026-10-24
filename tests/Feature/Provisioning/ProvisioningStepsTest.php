<?php

use App\Enums\AccountStatus;
use App\Enums\AgentJobStatus;
use App\Livewire\Accounts\AccountDetail;
use App\Models\Account;
use App\Models\AgentJob;
use App\Models\Package;
use App\Models\ProvisioningStep;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create();
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->customer = User::factory()->create(['role' => 'customer']);
    $this->package = Package::factory()->create();
});

test('provisioning steps are created in sequence with default pending status', function () {
    $job = AgentJob::factory()->create([
        'server_id' => $this->server->id,
        'action' => 'account.create',
        'status' => AgentJobStatus::RUNNING,
    ]);

    $steps = [
        'create_user' => 'Create isolated Linux user',
        'directories' => 'Create home & web root directories',
        'quota' => 'Configure disk quota & permissions',
        'php_pool' => 'Provision PHP-FPM isolated pool',
        'vhost' => 'Generate & test Nginx vhost',
        'ssl' => 'Issue default SSL/TLS certificate',
        'database' => 'Initialize database & credentials',
    ];

    foreach ($steps as $key => $label) {
        $job->createStep($key, $label);
    }

    expect($job->provisioningSteps)->toHaveCount(7);

    $firstStep = $job->provisioningSteps->first();
    expect($firstStep->key)->toBe('create_user')
        ->and($firstStep->label)->toBe('Create isolated Linux user')
        ->and($firstStep->isPending())->toBeTrue()
        ->and($job->overallProgressPercent())->toBe(0);
});

test('provisioning step transitions through running to done and updates progress percentage', function () {
    $job = AgentJob::factory()->create([
        'server_id' => $this->server->id,
        'action' => 'account.create',
        'status' => AgentJobStatus::RUNNING,
    ]);

    $step1 = $job->createStep('create_user', 'Create isolated Linux user');
    $step2 = $job->createStep('directories', 'Create home directories');

    expect($job->overallProgressPercent())->toBe(0);

    // Step 1 running
    $step1->markRunning('Executing useradd...');
    expect($step1->isRunning())->toBeTrue()
        ->and($step1->started_at)->not->toBeNull()
        ->and($step1->message)->toBe('Executing useradd...');

    // Step 1 done
    $step1->markDone('User created successfully');
    expect($step1->isDone())->toBeTrue()
        ->and($step1->finished_at)->not->toBeNull()
        ->and($step1->durationSeconds())->toBeGreaterThanOrEqual(0);

    // Progress is now 50%
    expect($job->overallProgressPercent())->toBe(50);

    // Step 2 done
    $step2->markDone('Directories verified');
    expect($job->overallProgressPercent())->toBe(100);
});

test('failure handling sets step status to failed and preserves error message', function () {
    $job = AgentJob::factory()->create([
        'server_id' => $this->server->id,
        'action' => 'account.create',
        'status' => AgentJobStatus::RUNNING,
    ]);

    $step = $job->createStep('vhost', 'Generate & test Nginx vhost');
    $step->markRunning();

    $step->markFailed('Nginx syntax error at line 42: invalid directive');

    expect($step->isFailed())->toBeTrue()
        ->and($step->message)->toBe('Nginx syntax error at line 42: invalid directive')
        ->and($step->finished_at)->not->toBeNull();
});

test('progress steps blade component renders all four states cleanly', function () {
    $steps = [
        [
            'key' => 'step_done',
            'label' => 'Step Completed',
            'status' => 'done',
            'message' => 'Done message',
        ],
        [
            'key' => 'step_running',
            'label' => 'Step Running',
            'status' => 'running',
            'message' => 'Running message',
        ],
        [
            'key' => 'step_pending',
            'label' => 'Step Pending',
            'status' => 'pending',
            'message' => null,
        ],
        [
            'key' => 'step_failed',
            'label' => 'Step Failed',
            'status' => 'failed',
            'message' => 'Failed error detail',
        ],
    ];

    $html = Blade::render(
        '<x-ui.progress-steps :steps="$steps" percent="25" retry-action="retryJob" />',
        ['steps' => $steps]
    );

    // Asserts all labels render
    expect($html)->toContain('Step Completed')
        ->and($html)->toContain('Step Running')
        ->and($html)->toContain('Step Pending')
        ->and($html)->toContain('Step Failed')
        ->and($html)->toContain('Stage Failed')
        ->and($html)->toContain('retryJob')
        ->and($html)->toContain('Retry')
        ->and($html)->toContain('width: 25%');
});

test('account detail page polls during provisioning and stops when complete', function () {
    $account = Account::factory()->create([
        'user_id' => $this->customer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'status' => AccountStatus::PENDING,
    ]);

    $job = AgentJob::factory()->create([
        'account_id' => $account->id,
        'server_id' => $this->server->id,
        'status' => AgentJobStatus::RUNNING,
    ]);

    $job->createStep('create_user', 'Create isolated Linux user')->markRunning();

    // While pending/running, wire:poll is rendered
    $component = Livewire::actingAs($this->admin)
        ->test(AccountDetail::class, ['account' => $account]);

    $component->assertSeeHtml('wire:poll.2s')
        ->assertSee('Account Provisioning Pipeline')
        ->assertSee('Create isolated Linux user');

    // Transition account to ACTIVE and job to COMPLETED
    $job->markCompleted(['success' => true]);
    $account->update(['status' => AccountStatus::ACTIVE]);

    // Re-render: wire:poll.2s must NOT be present
    Livewire::actingAs($this->admin)
        ->test(AccountDetail::class, ['account' => $account])
        ->assertDontSeeHtml('wire:poll.2s');
});
