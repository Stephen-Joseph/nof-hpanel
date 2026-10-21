<?php

namespace App\Jobs;

use App\Models\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SwitchDomainPhpVersionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<string, mixed>  $iniSettings
     */
    public function __construct(
        public Domain $domain,
        public string $newVersion,
        public array $iniSettings = [],
        public bool $dryRun = false
    ) {}

    public function handle(): void
    {
        $this->domain->loadMissing(['account.server', 'account.package']);
        $account = $this->domain->account;

        // 1. Ensure the PHP-FPM pool for the new version exists and is active
        WritePhpPoolJob::dispatchSync($account, $this->newVersion, $this->iniSettings, $this->dryRun);

        // 2. Update domain record
        $this->domain->update([
            'php_version' => $this->newVersion,
            'php_ini' => $this->iniSettings,
        ]);

        // 3. Re-write the Nginx virtual host with the updated fastcgi_pass socket path
        WriteVhostJob::dispatch($this->domain, $this->dryRun);
    }
}
