<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\UsageSnapshot;
use App\Services\AgentClient;
use App\Services\WebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CollectAccountUsageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Account $account,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client, WebhookService $webhookService): void
    {
        $this->account->loadMissing(['server', 'package', 'domains', 'databases', 'user']);
        $server = $this->account->server;

        /** @var list<string> $domains */
        $domains = $this->account->domains->pluck('domain')->values()->all();

        /** @var list<string> $databases */
        $databases = $this->account->databases->pluck('name')->values()->all();

        $payload = [
            'username' => $this->account->linux_username,
            'domains' => $domains,
            'databases' => $databases,
            'log_offsets' => $this->account->log_offsets ?? [],
        ];

        try {
            $response = $client->execute(
                server: $server,
                action: 'usage.collect',
                payload: $payload,
                dryRun: $this->dryRun
            );

            if (! $response['success']) {
                return;
            }

            $data = $response['data'];
            $storageMb = (float) ($data['storage_mb'] ?? 0);
            $inodes = (int) ($data['inodes'] ?? 0);
            $deltaBw = (int) ($data['bandwidth_bytes_total'] ?? 0);
            $dbBytes = (int) ($data['database_bytes_total'] ?? 0);
            $fpmActive = (int) ($data['fpm_active_workers'] ?? 0);
            $fpmIdle = (int) ($data['fpm_idle_workers'] ?? 0);
            $fpmTotal = (int) ($data['fpm_total_workers'] ?? 0);

            /** @var array<string, int> $newOffsets */
            $newOffsets = (array) ($data['new_log_offsets'] ?? []);

            // Record periodic snapshot
            UsageSnapshot::create([
                'account_id' => $this->account->id,
                'storage_mb' => $storageMb,
                'inodes' => $inodes,
                'bandwidth_bytes' => $deltaBw,
                'database_bytes' => $dbBytes,
                'fpm_active_workers' => $fpmActive,
                'fpm_idle_workers' => $fpmIdle,
                'fpm_total_workers' => $fpmTotal,
                'raw_metrics' => $data,
                'recorded_at' => now(),
            ]);

            // Update account state
            $currentLogOffsets = $this->account->log_offsets ?? [];
            $updatedLogOffsets = array_merge($currentLogOffsets, $newOffsets);

            $this->account->current_storage_mb = $storageMb;
            $this->account->current_inodes = $inodes;
            $this->account->current_bandwidth_bytes += $deltaBw;
            $this->account->log_offsets = $updatedLogOffsets;

            $this->evaluateUsageEnforcement($webhookService);

            $this->account->save();
        } catch (Throwable $e) {
            // Non-blocking for scheduled collector runs
            report($e);
        }
    }

    /**
     * Evaluate account resource usage percentages against package allowances and trigger notifications/enforcements.
     */
    protected function evaluateUsageEnforcement(WebhookService $webhookService): void
    {
        $storagePct = $this->account->storagePercentage();
        $inodesPct = $this->account->inodesPercentage();
        $bwPct = $this->account->bandwidthPercentage();
        $maxPct = max($storagePct, $inodesPct, $bwPct);

        $payload = [
            'account_id' => $this->account->id,
            'linux_username' => $this->account->linux_username,
            'primary_domain' => $this->account->primary_domain,
            'highest_percentage' => $maxPct,
            'storage' => [
                'used_mb' => $this->account->current_storage_mb,
                'limit_mb' => $this->account->package->storage_mb,
                'percentage' => $storagePct,
            ],
            'inodes' => [
                'used' => $this->account->current_inodes,
                'limit' => $this->account->package->inodes,
                'percentage' => $inodesPct,
            ],
            'bandwidth' => [
                'used_gb' => round($this->account->current_bandwidth_bytes / (1024 * 1024 * 1024), 2),
                'limit_gb' => $this->account->package->bandwidth_gb,
                'percentage' => $bwPct,
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        if ($maxPct >= 100) {
            if ($this->account->usage_warning_level < 100) {
                $this->account->usage_warning_level = 100;
                $this->account->is_throttled = true;

                $webhookService->dispatch('usage.limit_reached', $payload, $this->account->user);
            }
        } elseif ($maxPct >= 90) {
            if ($this->account->usage_warning_level < 90) {
                $this->account->usage_warning_level = 90;

                $webhookService->dispatch('usage.warning', array_merge($payload, ['tier' => 90]), $this->account->user);
            }
        } elseif ($maxPct >= 80) {
            if ($this->account->usage_warning_level < 80) {
                $this->account->usage_warning_level = 80;

                $webhookService->dispatch('usage.warning', array_merge($payload, ['tier' => 80]), $this->account->user);
            }
        } else {
            if ($this->account->usage_warning_level > 0) {
                $this->account->usage_warning_level = 0;
                $this->account->is_throttled = false;
            }
        }
    }
}
