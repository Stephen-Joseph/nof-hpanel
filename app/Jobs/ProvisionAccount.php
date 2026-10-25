<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Enums\AgentJobStatus;
use App\Models\Account;
use App\Models\AgentJob;
use App\Services\AgentClient;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProvisionAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 30];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Account $account,
        public bool $dryRun = false
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AgentClient $client, AuditService $audit): void
    {
        $this->account->loadMissing(['server', 'package', 'user']);
        $server = $this->account->server;
        $package = $this->account->package;

        $phpVersion = '8.3';
        if (! empty($package->allowed_php_versions)) {
            $phpVersion = (string) $package->allowed_php_versions[0];
        }

        $payload = [
            'username' => $this->account->linux_username,
            'primary_domain' => $this->account->primary_domain,
            'php_version' => $phpVersion,
            'storage_mb' => $package->storage_mb,
            'inodes' => $package->inodes,
            'php_workers' => $package->php_workers,
            'php_memory_mb' => $package->php_memory_mb,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'account.create',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        // Initialize provisioning pipeline steps
        $stepDefinitions = [
            'create_user' => 'Create isolated Linux user',
            'directories' => 'Create home & web root directories',
            'quota' => 'Configure disk quota & permissions',
            'php_pool' => 'Provision PHP-FPM isolated pool',
            'vhost' => 'Generate & test Nginx vhost',
            'ssl' => 'Issue default SSL/TLS certificate',
            'database' => 'Initialize database & credentials',
        ];

        foreach ($stepDefinitions as $key => $label) {
            $agentJob->createStep($key, $label);
        }

        $agentJob->getStep('create_user')?->markRunning('Setting up Linux user');

        $agentJob->recordLog(
            sprintf('Starting provisioning for %s on server %s', $this->account->linux_username, $server->name),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'account.create',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            if ($response['success']) {
                // Confirm all steps completed upon agent verification
                foreach ($agentJob->provisioningSteps as $step) {
                    if (! $step->isDone()) {
                        $step->markDone('Verified by server agent');
                    }
                }

                $agentJob->markCompleted($response['data']);
                $this->account->transitionTo(AccountStatus::ACTIVE);

                $audit->log(
                    action: 'account.provisioned',
                    target: $this->account,
                    oldValues: ['status' => AccountStatus::PENDING->value],
                    newValues: [
                        'status' => AccountStatus::ACTIVE->value,
                        'server_id' => $server->id,
                        'job_id' => $agentJob->id,
                    ],
                    actor: $this->account->user
                );
            } else {
                $err = $response['error'] ?? 'Agent indicated provisioning failure';
                $currentStep = $agentJob->provisioningSteps()->where('status', 'running')->first() 
                    ?? $agentJob->provisioningSteps()->where('status', 'pending')->first();
                $currentStep?->markFailed($err);

                $agentJob->markFailed($err);
                $this->account->transitionTo(AccountStatus::FAILED, $err);
            }
        } catch (Throwable $e) {
            $err = \Illuminate\Support\Str::limit($e->getMessage(), 500, '');
            $agentJob->recordLog('Provisioning error: '.$err, 'error');
            
            $currentStep = $agentJob->provisioningSteps()->where('status', 'running')->first() 
                ?? $agentJob->provisioningSteps()->where('status', 'pending')->first();
            $currentStep?->markFailed($err);

            $agentJob->markFailed($err);

            if ($this->account->canTransitionTo(AccountStatus::FAILED)) {
                $this->account->transitionTo(AccountStatus::FAILED, $err);
            }
        }
    }

    /**
     * Handle job permanent failure.
     */
    public function failed(?Throwable $exception): void
    {
        if ($this->account->canTransitionTo(AccountStatus::FAILED)) {
            $this->account->transitionTo(AccountStatus::FAILED, $exception?->getMessage() ?? 'Provisioning failed');
        }
    }
}
