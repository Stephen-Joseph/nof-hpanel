<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Server;
use App\Services\AgentClient;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MigrateAccountServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Account $account,
        public Server $targetServer,
        public bool $dryRun = false
    ) {}

    public function handle(AgentClient $client): void
    {
        $this->account->loadMissing(['server', 'package', 'domains', 'databases', 'cronJobs', 'user']);
        $sourceServer = $this->account->server;

        if ($sourceServer->id === $this->targetServer->id) {
            throw new DomainException('Source and target migration servers must be distinct.');
        }

        if (! $this->targetServer->status->canAcceptAccounts()) {
            throw new DomainException("Target server '{$this->targetServer->name}' is not in active state (status: {$this->targetServer->status->value}).");
        }

        // Step 1: Create backup snapshot on source server
        $backupPayload = [
            'username' => $this->account->linux_username,
            'backup_type' => 'full',
            'retention_keep' => 1,
        ];

        $sourceResp = $client->execute(
            server: $sourceServer,
            action: 'backup.create',
            payload: $backupPayload,
            dryRun: $this->dryRun
        );

        $snapshotId = (string) ($sourceResp['data']['snapshot_id'] ?? 'mig_snap_'.now()->format('YmdHis'));

        // Step 2: Provision account shell on target server
        $provisionPayload = [
            'username' => $this->account->linux_username,
            'primary_domain' => $this->account->primary_domain,
            'storage_mb' => $this->account->package->storage_mb,
            'inodes' => $this->account->package->inodes,
            'php_workers' => $this->account->package->php_workers,
            'php_memory_mb' => $this->account->package->php_memory_mb,
            'ssh_enabled' => $this->account->package->ssh_enabled,
        ];

        $client->execute(
            server: $this->targetServer,
            action: 'account.create',
            payload: $provisionPayload,
            dryRun: $this->dryRun
        );

        // Step 3: Restore snapshot onto target server
        $restorePayload = [
            'username' => $this->account->linux_username,
            'snapshot_id' => $snapshotId,
            'restore_type' => 'full',
        ];

        $client->execute(
            server: $this->targetServer,
            action: 'backup.restore',
            payload: $restorePayload,
            dryRun: $this->dryRun
        );

        // Step 4: Re-render virtual hosts on target server
        foreach ($this->account->domains as $domain) {
            $vhostPayload = [
                'username' => $this->account->linux_username,
                'domain' => $domain->domain,
                'document_root' => $domain->document_root,
                'app_profile' => $domain->app_profile->value,
                'php_version' => $domain->php_version,
                'force_https' => $domain->force_https,
            ];

            $client->execute(
                server: $this->targetServer,
                action: 'vhost.write',
                payload: $vhostPayload,
                dryRun: $this->dryRun
            );
        }

        // Step 5: Atomically update server assignment in panel database
        $this->account->update([
            'server_id' => $this->targetServer->id,
        ]);

        AuditLog::create([
            'user_id' => $this->account->user_id,
            'action' => 'account.migrated',
            'target_type' => Account::class,
            'target_id' => $this->account->id,
            'old_values' => ['server_id' => $sourceServer->id, 'server_name' => $sourceServer->name],
            'new_values' => ['server_id' => $this->targetServer->id, 'server_name' => $this->targetServer->name],
            'ip_address' => '127.0.0.1',
        ]);
    }
}
