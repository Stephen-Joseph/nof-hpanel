<?php

namespace App\Jobs;

use App\Models\AgentJob;
use App\Services\AgentClient;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteAgentAction implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 30];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public AgentJob $agentJob
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AgentClient $client, AuditService $audit): void
    {
        $this->agentJob->loadMissing(['server', 'account']);
        $server = $this->agentJob->server;

        $this->agentJob->markRunning();
        $this->agentJob->recordLog(
            sprintf("Dispatching action '%s' to server '%s' (%s)", $this->agentJob->action, $server->name, $server->hostname),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: $this->agentJob->action,
                payload: $this->agentJob->payload ?? [],
                dryRun: $this->agentJob->dry_run,
                requestId: $this->agentJob->id
            );

            // Record log lines returned by the agent
            foreach ($response['logs'] as $logLine) {
                $this->agentJob->recordLog($logLine, 'info');
            }

            if ($response['success']) {
                $this->agentJob->markCompleted($response['data']);
                $this->agentJob->recordLog("Action '{$this->agentJob->action}' completed successfully", 'info');

                $audit->log(
                    action: 'agent.action_completed',
                    target: $this->agentJob,
                    oldValues: ['status' => 'running'],
                    newValues: [
                        'status' => 'completed',
                        'action' => $this->agentJob->action,
                        'server_id' => $server->id,
                        'duration_attempts' => $this->agentJob->attempts,
                    ],
                    actor: $this->agentJob->dispatcher
                );
            } else {
                $errorMessage = $response['error'] ?? 'Agent indicated action failure';
                $this->agentJob->markFailed($errorMessage);
                $this->agentJob->recordLog("Action returned failure: {$errorMessage}", 'error');
            }
        } catch (Throwable $e) {
            $this->agentJob->recordLog('Execution error: '.$e->getMessage(), 'error');

            if ($this->attempts() >= $this->tries) {
                $this->agentJob->markFailed($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->agentJob->markFailed($exception?->getMessage() ?? 'Job failed after max attempts');
        $this->agentJob->recordLog('Job marked as failed permanently: '.($exception?->getMessage() ?? 'Unknown error'), 'error');
    }
}
