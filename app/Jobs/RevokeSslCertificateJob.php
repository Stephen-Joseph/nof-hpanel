<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Enums\CertificateStatus;
use App\Models\AgentJob;
use App\Models\Domain;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RevokeSslCertificateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Domain $domain,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->domain->loadMissing(['account.server', 'certificate']);
        $account = $this->domain->account;
        $server = $account->server;

        $payload = [
            'domain' => $this->domain->domain,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'ssl.revoke',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        try {
            $response = $client->execute(
                server: $server,
                action: 'ssl.revoke',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            if ($this->domain->certificate) {
                $this->domain->certificate->update([
                    'status' => CertificateStatus::REVOKED,
                ]);
            }

            // Turn off force_https if revoked
            $this->domain->update(['force_https' => false]);

            $agentJob->markCompleted($response['data']);

            // Re-render vhost without SSL
            WriteVhostJob::dispatch($this->domain);
        } catch (Throwable $e) {
            $agentJob->recordLog('SSL revoke error: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());

            throw $e;
        }
    }
}

