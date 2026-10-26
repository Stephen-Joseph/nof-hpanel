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

class TerminateAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Account $account,
        public bool $takeBackup = true,
        public bool $dryRun = false
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AgentClient $client, AuditService $audit): void
    {
        $this->account->loadMissing(['server', 'user']);
        $server = $this->account->server;

        $previousStatus = $this->account->status;
        if ($this->account->status === AccountStatus::PENDING) {
            $this->account->transitionTo(AccountStatus::FAILED, 'Terminated while pending');
            return;
        }
        $this->account->transitionTo(AccountStatus::TERMINATING);

        $payload = [
            'username' => $this->account->linux_username,
            'confirm_token' => 'TERMINATE-'.$this->account->linux_username,
            'take_backup' => $this->takeBackup,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $this->account->id,
            'dispatched_by' => $this->account->user_id,
            'action' => 'account.terminate',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf('Terminating account %s on server %s (backup=%s)', $this->account->linux_username, $server->name, $this->takeBackup ? 'true' : 'false'),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'account.terminate',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            if ($response['success']) {
                $agentJob->markCompleted($response['data']);
                $this->account->transitionTo(AccountStatus::TERMINATED);

                try {
                    $audit->log(
                        action: 'account.terminated',
                        target: $this->account,
                        oldValues: ['status' => $previousStatus->value],
                        newValues: [
                            'status' => AccountStatus::TERMINATED->value,
                            'backup_path' => $response['data']['backup_path'] ?? null,
                        ],
                        actor: $this->account->user
                    );
                } catch (\Throwable $e) {
                    logger()->error('Audit log write failed: ' . $e->getMessage());
                }
            } else {
                $err = $response['error'] ?? 'Agent indicated termination failure';
                if (is_array($err)) {
                    $parts = array_filter([$err['message'] ?? null, $err['details'] ?? null]);
                    $err = !empty($parts) ? implode(' | ', $parts) : json_encode($err);
                }
                $err = (string) $err;
                $agentJob->markFailed($err);
                $this->account->transitionTo(AccountStatus::FAILED, $err);
            }
        } catch (Throwable $e) {
            $agentJob->recordLog('Termination error: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());

            if ($this->account->canTransitionTo(AccountStatus::FAILED)) {
                $this->account->transitionTo(AccountStatus::FAILED, $e->getMessage());
            }

            throw $e;
        }
    }
}
