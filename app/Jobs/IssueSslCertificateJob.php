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

        // Ensure domain is verified before proceeding with SSL request
        if ($this->domain->verification_status !== 'verified') {
            $msg = 'Domain must be verified before requesting SSL.';
            $this->domain->update([
                'ssl_status' => 'failed',
                'ssl_last_error' => $msg,
            ]);

            return;
        }

        $this->domain->update([
            'ssl_status' => 'pending',
            'ssl_requested_at' => now(),
            'ssl_last_error' => null,
        ]);

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
                $validTo = isset($data['valid_to']) ? Carbon::parse($data['valid_to']) : now()->addDays(90);

                $cert->update([
                    'status' => CertificateStatus::ACTIVE,
                    'cert_path' => $data['cert_path'] ?? null,
                    'key_path' => $data['key_path'] ?? null,
                    'issuer' => $data['issuer'] ?? "Let's Encrypt",
                    'valid_from' => isset($data['valid_from']) ? Carbon::parse($data['valid_from']) : now(),
                    'valid_to' => $validTo,
                    'last_error' => null,
                ]);

                $this->domain->update([
                    'ssl_status' => 'active',
                    'ssl_expires_at' => $validTo,
                    'ssl_last_error' => null,
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
                $shortError = substr($errorMsg, 0, 500);

                $cert->update([
                    'status' => CertificateStatus::FAILED,
                    'last_error' => $shortError,
                ]);

                $this->domain->update([
                    'ssl_status' => 'failed',
                    'ssl_last_error' => $shortError,
                ]);

                $agentJob->markFailed($shortError);

                $webhooks->dispatch('ssl.failed', [
                    'domain' => $this->domain->domain,
                    'error' => $shortError,
                ], $account->user);
            }
        } catch (Throwable $e) {
            $shortError = substr($e->getMessage(), 0, 500);

            $cert->update([
                'status' => CertificateStatus::FAILED,
                'last_error' => $shortError,
            ]);

            $this->domain->update([
                'ssl_status' => 'failed',
                'ssl_last_error' => $shortError,
            ]);

            $agentJob->recordLog('SSL issuance error: '.$shortError, 'error');
            $agentJob->markFailed($shortError);

            $webhooks->dispatch('ssl.failed', [
                'domain' => $this->domain->domain,
                'error' => $shortError,
            ], $account->user);
        }
    }
}
