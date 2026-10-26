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

class UnsuspendAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

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
        $this->account->loadMissing(['server', 'user']);
        $server = $this->account->server;

        $payload = [
            'username' => $this->account->linux_username,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'account.unsuspend',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf('Unsuspending account %s on server %s', $this->account->linux_username, $server->name),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'account.unsuspend',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            if ($response['success']) {
                $agentJob->markCompleted($response['data']);
                $this->account->transitionTo(AccountStatus::ACTIVE);

                try {
                    $audit->log(
                        action: 'account.unsuspended',
                        target: $this->account,
                        oldValues: ['status' => AccountStatus::SUSPENDED->value],
                        newValues: [
                            'status' => AccountStatus::ACTIVE->value,
                        ],
                        actor: $this->account->user
                    );
                } catch (\Throwable $e) {
                    logger()->error('Audit log write failed: ' . $e->getMessage());
                }
            } else {
                $err = $response['error'] ?? 'Agent indicated unsuspension failure';
                if (is_array($err)) {
                    $parts = array_filter([$err['message'] ?? null, $err['details'] ?? null]);
                    $err = !empty($parts) ? implode(' | ', $parts) : json_encode($err);
                }
                $agentJob->markFailed((string) $err);
            }
        } catch (Throwable $e) {
            $agentJob->recordLog('Unsuspension error: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());

            throw $e;
        }
    }
}
