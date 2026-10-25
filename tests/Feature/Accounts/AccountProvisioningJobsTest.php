<?php

use App\Enums\AccountStatus;
use App\Enums\AgentJobStatus;
use App\Jobs\ProvisionAccount;
use App\Jobs\SuspendAccount;
use App\Jobs\TerminateAccount;
use App\Jobs\UnsuspendAccount;
use App\Jobs\UpgradeAccount;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use App\Services\AuditService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.agent.secret', 'prov-secret-xyz');

    $this->server = Server::factory()->create([
        'agent_url' => 'https://10.0.0.50:8443',
    ]);
    $this->user = User::factory()->customer()->create();
    $this->package = Package::factory()->create([
        'name' => 'Starter Pack',
        'storage_mb' => 10240,
        'inodes' => 100000,
        'php_workers' => 6,
        'php_memory_mb' => 256,
        'allowed_php_versions' => ['8.3'],
    ]);

    $this->account = Account::factory()->pending()->create([
        'user_id' => $this->user->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'custest1',
        'primary_domain' => 'custest1.org',
    ]);

    $this->client = new AgentClient;
    $this->audit = app(AuditService::class);
});

test('ProvisionAccount job sends account.create action and activates account on success', function () {
    Http::fake([
        'https://10.0.0.50:8443/action' => Http::response([
            'request_id' => 'req-prov-1',
            'action' => 'account.create',
            'success' => true,
            'data' => [
                'username' => 'custest1',
                'home_dir' => '/home/custest1',
            ],
            'logs' => ['created user custest1', 'deployed pool'],
            'executed_at' => 1774000000,
        ], 200),
    ]);

    $job = new ProvisionAccount($this->account);
    $job->handle($this->client, $this->audit);

    $this->account->refresh();

    expect($this->account->status)->toBe(AccountStatus::ACTIVE);

    $this->assertDatabaseHas('agent_jobs', [
        'account_id' => $this->account->id,
        'action' => 'account.create',
        'status' => AgentJobStatus::COMPLETED->value,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'account.provisioned',
        'target_id' => $this->account->id,
    ]);
});

test('ProvisionAccount marks account failed when agent reports error', function () {
    Http::fake([
        'https://10.0.0.50:8443/action' => Http::response([
            'request_id' => 'req-prov-fail',
            'action' => 'account.create',
            'success' => false,
            'error' => 'Disk quota exceeded on node storage pool',
            'logs' => ['storage pool full'],
            'executed_at' => 1774000000,
        ], 200),
    ]);

    $job = new ProvisionAccount($this->account);
    $job->handle($this->client, $this->audit);

    $this->account->refresh();

    expect($this->account->status)->toBe(AccountStatus::FAILED)
        ->and($this->account->suspended_reason)->toBe('Disk quota exceeded on node storage pool');
});

test('SuspendAccount and UnsuspendAccount execute signed agent actions', function () {
    $this->account->update(['status' => AccountStatus::ACTIVE]);

    Http::fake([
        'https://10.0.0.50:8443/action' => Http::sequence()
            ->push([
                'request_id' => 'req-susp-1',
                'action' => 'account.suspend',
                'success' => true,
                'data' => ['suspended' => true],
                'logs' => ['pool deactivated'],
            ], 200)
            ->push([
                'request_id' => 'req-unsusp-1',
                'action' => 'account.unsuspend',
                'success' => true,
                'data' => ['suspended' => false],
                'logs' => ['pool restored'],
            ], 200),
    ]);

    // Suspend
    $suspendJob = new SuspendAccount($this->account, 'Payment overdue');
    $suspendJob->handle($this->client, $this->audit);

    $this->account->refresh();
    expect($this->account->status)->toBe(AccountStatus::SUSPENDED)
        ->and($this->account->suspended_reason)->toBe('Payment overdue');

    // Unsuspend
    $unsuspendJob = new UnsuspendAccount($this->account);
    $unsuspendJob->handle($this->client, $this->audit);

    $this->account->refresh();
    expect($this->account->status)->toBe(AccountStatus::ACTIVE)
        ->and($this->account->suspended_reason)->toBeNull();
});

test('UpgradeAccount executes account.upgrade and switches package', function () {
    $newPackage = Package::factory()->create([
        'name' => 'Pro Enterprise',
        'storage_mb' => 40960,
    ]);

    Http::fake([
        'https://10.0.0.50:8443/action' => Http::response([
            'request_id' => 'req-upgr-1',
            'action' => 'account.upgrade',
            'success' => true,
            'data' => ['storage_mb' => 40960],
            'logs' => ['quota updated'],
        ], 200),
    ]);

    $upgradeJob = new UpgradeAccount($this->account, $newPackage);
    $upgradeJob->handle($this->client, $this->audit);

    $this->account->refresh();
    expect($this->account->package_id)->toBe($newPackage->id);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'account.upgraded',
        'target_id' => $this->account->id,
    ]);
});

test('TerminateAccount sends confirmation token and purges account', function () {
    $this->account->update(['status' => AccountStatus::ACTIVE]);

    Http::fake([
        'https://10.0.0.50:8443/action' => Http::response([
            'request_id' => 'req-term-1',
            'action' => 'account.terminate',
            'success' => true,
            'data' => ['terminated' => true, 'backup_path' => '/var/backups/panel-agent/custest1-final.tar.gz'],
            'logs' => ['user deleted'],
        ], 200),
    ]);

    $termJob = new TerminateAccount($this->account, true);
    $termJob->handle($this->client, $this->audit);

    $this->account->refresh();
    expect($this->account->status)->toBe(AccountStatus::TERMINATED);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'account.terminated',
        'target_id' => $this->account->id,
    ]);
});

test('ProvisionAccount job handles 422 agent response and transitions to failed without escaping exception', function () {
    Http::fake([
        'https://10.0.0.50:8443/action' => Http::response([
            'request_id' => 'req-prov-422',
            'action' => 'account.create',
            'success' => false,
            'error' => 'validation failed: storage_mb must be greater than 0',
        ], 422),
    ]);

    expect($this->account->status)->toBe(AccountStatus::PENDING);

    $job = new ProvisionAccount($this->account);
    // Assert no exception escapes
    $job->handle($this->client, $this->audit);

    $this->account->refresh();

    expect($this->account->status)->toBe(AccountStatus::FAILED)
        ->and($this->account->suspended_reason)->toContain('validation failed: storage_mb must be greater than 0');

    $this->assertDatabaseHas('agent_jobs', [
        'account_id' => $this->account->id,
        'action' => 'account.create',
        'status' => AgentJobStatus::FAILED->value,
    ]);
});

test('ProvisionAccount job handles 500 agent step failure and transitions to failed without escaping exception', function () {
    Http::fake([
        'https://10.0.0.50:8443/action' => Http::response([
            'request_id' => 'req-prov-500',
            'action' => 'account.create',
            'success' => false,
            'error' => "step 'create_user' failed: useradd exited with status 1",
        ], 500),
    ]);

    expect($this->account->status)->toBe(AccountStatus::PENDING);

    $job = new ProvisionAccount($this->account);
    // Assert no exception escapes
    $job->handle($this->client, $this->audit);

    $this->account->refresh();

    expect($this->account->status)->toBe(AccountStatus::FAILED)
        ->and($this->account->suspended_reason)->toContain("step 'create_user' failed");

    $this->assertDatabaseHas('agent_jobs', [
        'account_id' => $this->account->id,
        'action' => 'account.create',
        'status' => AgentJobStatus::FAILED->value,
    ]);
});

test('failed provisioning leaves the account in FAILED without DomainException and can be terminated', function () {
    expect($this->account->status)->toBe(AccountStatus::PENDING);

    // Simulate direct failure
    $this->account->transitionTo(AccountStatus::FAILED, 'Initial provisioning failure');
    expect($this->account->status)->toBe(AccountStatus::FAILED);

    // Terminating a pending or failed account should not throw DomainException
    Http::fake([
        'https://10.0.0.50:8443/action' => Http::response([
            'request_id' => 'req-term-failed',
            'action' => 'account.terminate',
            'success' => true,
            'data' => ['terminated' => true],
            'logs' => ['cleaned up'],
        ], 200),
    ]);

    $termJob = new TerminateAccount($this->account, false);
    $termJob->handle($this->client, $this->audit);

    $this->account->refresh();
    expect($this->account->status)->toBe(AccountStatus::TERMINATED);
});

test('terminating a pending account transitions safely without DomainException', function () {
    expect($this->account->status)->toBe(AccountStatus::PENDING);

    Http::fake([
        'https://10.0.0.50:8443/action' => Http::response([
            'request_id' => 'req-term-pending',
            'action' => 'account.terminate',
            'success' => true,
            'data' => ['terminated' => true],
            'logs' => ['cleaned up'],
        ], 200),
    ]);

    $termJob = new TerminateAccount($this->account, false);
    $termJob->handle($this->client, $this->audit);

    $this->account->refresh();
    expect($this->account->status)->toBe(AccountStatus::TERMINATED);
});
