<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Jobs\CollectAccountUsageJob;
use App\Models\Account;
use Illuminate\Console\Command;

class CollectUsageMetricsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'usage:collect {--account= : Specific account ID to poll} {--dry-run : Dry run simulation mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll server agent nodes for account disk, inode, bandwidth, and database metrics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountId = $this->option('account');
        $dryRun = (bool) $this->option('dry-run');

        $query = Account::with(['server', 'package', 'domains', 'databases'])
            ->where('status', AccountStatus::ACTIVE);

        if ($accountId) {
            $query->where('id', $accountId);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->info('No active accounts found for usage collection.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Queueing usage collection for %d account(s)...', $accounts->count()));

        foreach ($accounts as $account) {
            CollectAccountUsageJob::dispatch($account, $dryRun);
        }

        $this->info('Usage collection jobs dispatched successfully.');

        return self::SUCCESS;
    }
}
