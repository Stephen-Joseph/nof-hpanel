<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;

class ResetMonthlyBandwidthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bandwidth:reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset monthly bandwidth consumption and restore throttled accounts on the 1st of each month';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $updatedCount = Account::query()->update([
            'current_bandwidth_bytes' => 0,
            'bandwidth_reset_at' => now(),
            'is_throttled' => false,
            'usage_warning_level' => 0,
        ]);

        $this->info(sprintf('Successfully reset monthly bandwidth counters for %d account(s).', $updatedCount));

        return self::SUCCESS;
    }
}
