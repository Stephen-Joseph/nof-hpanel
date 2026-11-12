<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Domain;
use App\Services\PrimaryDomainService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class BackfillPrimaryDomainsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hpanel:backfill-domains';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create missing primary domain rows for existing hosting accounts.';

    /**
     * Execute the console command.
     */
    public function handle(PrimaryDomainService $primaryDomains): int
    {
        $created = 0;
        $skipped = 0;
        $errors = 0;

        Account::with('package')
            ->where('status', '!=', AccountStatus::TERMINATED)
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($primaryDomains, &$created, &$skipped, &$errors) {
                foreach ($accounts as $account) {
                    $domainName = strtolower(trim($account->primary_domain));

                    $alreadyExists = Domain::where('account_id', $account->id)
                        ->where('domain', $domainName)
                        ->exists();

                    if ($alreadyExists) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $primaryDomains->ensureForAccount($account);
                        $created++;
                        $this->line(sprintf('Created primary domain %s for account #%d (%s).', $domainName, $account->id, $account->linux_username));
                    } catch (ValidationException $e) {
                        $errors++;
                        $this->warn(sprintf('Skipped account #%d (%s): %s', $account->id, $domainName, $e->getMessage()));
                    }
                }
            });

        $this->info(sprintf('Backfill complete: %d created, %d skipped, %d failed.', $created, $skipped, $errors));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
