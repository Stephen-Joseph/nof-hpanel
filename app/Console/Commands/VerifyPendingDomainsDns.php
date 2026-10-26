<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\DomainVerificationService;
use Illuminate\Console\Command;

class VerifyPendingDomainsDns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'domains:verify-pending {--limit=200 : Maximum number of domains to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-run DNS verification checks for domains in pending_dns status.';

    /**
     * Execute the console command.
     */
    public function handle(DomainVerificationService $service): int
    {
        $limit = (int) $this->option('limit');

        $domains = Domain::where('verification_status', 'pending_dns')
            ->orderBy('dns_last_checked_at', 'asc')
            ->limit($limit)
            ->get();

        $this->info(sprintf('Checking DNS for %d pending domain(s)...', $domains->count()));

        $verifiedCount = 0;
        foreach ($domains as $domain) {
            $result = $service->verify($domain);
            if ($result->verified) {
                $verifiedCount++;
                $this->line(sprintf('Domain %s verified successfully.', $domain->domain));
            } else {
                $this->line(sprintf('Domain %s check failed: %s', $domain->domain, $result->error ?? 'Unresolved'));
            }
        }

        $this->info(sprintf('Finished DNS checks: %d verified out of %d checked.', $verifiedCount, $domains->count()));

        return Command::SUCCESS;
    }
}

