<?php

namespace App\Jobs;

use App\Enums\AgentJobStatus;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Models\AgentJob;
use App\Models\Certificate;
use App\Models\Domain;
use App\Services\AgentClient;
use App\Services\WebhookService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class IssueSslCertificateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  list<string>  $altNames
     */
    public function __construct(
        public Domain $domain,
        public string $email,
        public array $altNames = [],
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client, WebhookService $webhooks): void
    {
        $this->domain->loadMissing('account.server');
        $account = $this->domain->account;
        $server = $account->server;

        /** @var Certificate $cert */
        $cert = Certificate::updateOrCreate(
            ['domain_id' => $this->domain->id],
            [
                'type' => CertificateType::LETS_ENCRYPT,
                'status' => CertificateStatus::PENDING,
                'auto_renew' => true,
                'last_error' => null,
            ]
        );

        $payload = [
            'domain' => $this->domain->domain,
            'email' => $this->email,
            'alt_names' => $this->altNames,
            'agree_tos' => true,
        ];

        $agentJob = AgentJob::create([
            'server_id' => $server->id,
            'account_id' => $account->id,
            'dispatched_by' => $account->user_id,
            'action' => 'ssl.issue',
            'status' => AgentJobStatus::RUNNING,
            'payload' => $payload,
            'dry_run' => $this->dryRun,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Requesting Let's Encrypt certificate for %s", $this->domain->domain),
            'info'
        );

        try {
            $response = $client->execute(
                server: $server,
                action: 'ssl.issue',
                payload: $payload,
                dryRun: $this->dryRun,
                requestId: $agentJob->id
            );

            foreach ($response['logs'] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            if ($response['success']) {
                $data = $response['data'];
                $cert->update([
                    'status' => CertificateStatus::ACTIVE,
                    'cert_path' => $data['cert_path'] ?? null,
                    'key_path' => $data['key_path'] ?? null,
                    'issuer' => $data['issuer'] ?? "Let's Encrypt",
                    'valid_from' => isset($data['valid_from']) ? Carbon::parse($data['valid_from']) : now(),
                    'valid_to' => isset($data['valid_to']) ? Carbon::parse($data['valid_to']) : now()->addDays(90),
                    'last_error' => null,
                ]);

                $agentJob->markCompleted($data);

                // Dispatch webhook notification
                $webhooks->dispatch('ssl.issued', [
                    'domain' => $this->domain->domain,
                    'issuer' => $cert->issuer,
                    'valid_to' => $cert->valid_to?->toIso8601String(),
                ], $account->user);

                // Re-write virtual host to enable SSL block and reload Nginx
                WriteVhostJob::dispatch($this->domain);
            } else {
                $errorMsg = $response['error'] ?? 'Certificate issuance failed';
                $cert->update([
                    'status' => CertificateStatus::FAILED,
                    'last_error' => $errorMsg,
                ]);

                $agentJob->markFailed($errorMsg);

                $webhooks->dispatch('ssl.failed', [
                    'domain' => $this->domain->domain,
                    'error' => $errorMsg,
                ], $account->user);
            }
        } catch (Throwable $e) {
            $cert->update([
                'status' => CertificateStatus::FAILED,
                'last_error' => $e->getMessage(),
            ]);

            $agentJob->recordLog('SSL issuance error: '.$e->getMessage(), 'error');
            $agentJob->markFailed($e->getMessage());

            $webhooks->dispatch('ssl.failed', [
                'domain' => $this->domain->domain,
                'error' => $e->getMessage(),
            ], $account->user);

            throw $e;
        }
    }
}

