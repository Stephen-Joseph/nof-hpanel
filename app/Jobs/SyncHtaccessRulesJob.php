<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Domain;
use App\Services\AgentClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncHtaccessRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public Domain $domain) {}

    public function handle(AgentClient $client): void
    {
        $domain = $this->domain->loadMissing('account.server');
        $server = $domain->account?->server;

        if (! $server || $server->status !== ServerStatus::ACTIVE) {
            Log::info("Skipping .htaccess sync for {$domain->domain}: server is inactive or missing.");
            return;
        }

        try {
            $res = $client->execute(
                server: $server,
                action: 'htaccess.sync',
                payload: [
                    'username' => $domain->account->linux_username,
                    'domain' => $domain->domain,
                    'document_root' => $domain->document_root,
                ],
                dryRun: false
            );

            if ($res['success']) {
                $report = $res['data'] ?? [];
                $report['synced_at'] = now()->toIso8601String();

                $domain->update([
                    'htaccess_report' => $report,
                ]);
            } else {
                Log::warning("Agent reported failure during .htaccess sync for {$domain->domain}: " . ($res['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            Log::error("Failed syncing .htaccess for {$domain->domain}: " . $e->getMessage());
            throw $e;
        }
    }
}
