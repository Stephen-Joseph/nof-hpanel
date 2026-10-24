<?php

namespace App\Console\Commands;

use App\Models\ApiRequestLog;
use Illuminate\Console\Command;

class PruneApiRequestLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:prune-logs {--days=30 : Number of days to retain logs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune API request logs older than specified days (defaults to 30 days)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = ApiRequestLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} API request logs older than {$days} days.");

        return self::SUCCESS;
    }
}

