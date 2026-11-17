<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\DomainType;
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
    protected $signature = 'hpanel:backfill-domains {--repair : Create missing domain rows, fix primary types, and generate missing tokens}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create missing primary domain rows, fix primary type, and generate tokens only where missing.';

    /**
     * Execute the console command.
     */
    public function handle(PrimaryDomainService $primaryDomains): int
    {
        $repair = (bool) $this->option('repair');

        $created = 0;
        $repairedType = 0;
        $tokensGenerated = 0;
        $skipped = 0;
        $errors = 0;

        Account::with('package')
            ->where('status', '!=', AccountStatus::TERMINATED)
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($primaryDomains, $repair, &$created, &$repairedType, &$tokensGenerated, &$skipped, &$errors) {
                foreach ($accounts as $account) {
                    $domainName = strtolower(trim($account->primary_domain));

                    /** @var Domain|null $existing */
                    $existing = Domain::where('account_id', $account->id)
                        ->where('domain', $domainName)
                        ->first();

                    if (! $existing) {
                        try {
                            $primaryDomains->ensureForAccount($account);
                            $created++;
                            $this->line(sprintf('Created primary domain %s for account #%d (%s).', $domainName, $account->id, $account->linux_username));
                        } catch (ValidationException $e) {
                            $errors++;
                            $this->warn(sprintf('Skipped account #%d (%s): %s', $account->id, $domainName, $e->getMessage()));
                        }
                    } else {
                        $dirty = false;
                        if ($repair) {
                            if (! $existing->is_primary || $existing->type !== DomainType::PRIMARY) {
                                $existing->type = DomainType::PRIMARY;
                                $existing->is_primary = true;
                                $repairedType++;
                                $dirty = true;
                            }
                            if (empty($existing->verification_token)) {
                                $existing->verification_token = bin2hex(random_bytes(16));
                                $tokensGenerated++;
                                $dirty = true;
                            }
                            if ($dirty) {
                                $existing->save();
                                $this->line(sprintf('Repaired primary domain %s for account #%d.', $domainName, $account->id));
                            } else {
                                $skipped++;
                            }
                        } else {
                            $skipped++;
                        }
                    }
                }
            });

        if ($repair) {
            // Also generate tokens for any other domain in the table where verification_token is missing
            Domain::whereNull('verification_token')
                ->orWhere('verification_token', '')
                ->chunkById(100, function ($domains) use (&$tokensGenerated) {
                    foreach ($domains as $d) {
                        $d->verification_token = bin2hex(random_bytes(16));
                        $d->save();
                        $tokensGenerated++;
                    }
                });
        }

        $this->info(sprintf(
            'Backfill complete: %d created, %d types fixed, %d tokens generated, %d skipped, %d failed.',
            $created,
            $repairedType,
            $tokensGenerated,
            $skipped,
            $errors
        ));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
