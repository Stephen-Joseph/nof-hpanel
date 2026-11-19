<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\DomainVerificationService;
use Illuminate\Console\Command;

class BackfillNodeEdgeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hpanel:backfill-nodes {--force : Run non-interactively without prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill public IP and edge host addressing for all server nodes, and re-run domain verification.';

    /**
     * Execute the console command.
     */
    public function handle(DomainVerificationService $verificationService): int
    {
        $servers = Server::with(['accounts.domains'])->orderBy('id')->get();

        if ($servers->isEmpty()) {
            $this->info('No server nodes registered.');

            return self::SUCCESS;
        }

        $this->info("Processing {$servers->count()} server node(s)...");

        $rows = [];
        $totalDomainsReverified = 0;

        foreach ($servers as $server) {
            $changed = false;
            $publicIp = $server->public_ip;
            $edgeHost = $server->edge_host;

            // 1. Determine public_ip if missing
            if (empty($publicIp)) {
                $host = parse_url($server->agent_url ?? '', PHP_URL_HOST);
                if (! empty($host) && filter_var($host, FILTER_VALIDATE_IP)) {
                    $publicIp = $host;
                    $changed = true;
                } elseif (! $this->option('force') && $this->input->isInteractive()) {
                    $prompted = $this->ask("Enter public IPv4 address for server '{$server->name}' ({$server->hostname})");
                    if (! empty($prompted)) {
                        $publicIp = trim($prompted);
                        $changed = true;
                    }
                }
            }

            // 2. Determine edge_host if missing
            if (empty($edgeHost)) {
                $edgeHost = Server::generateDefaultEdgeHost($server->name);
                $changed = true;
            }

            $domainsReverified = 0;
            if ($changed) {
                $server->update([
                    'public_ip' => $publicIp,
                    'edge_host' => $edgeHost,
                ]);

                // Re-run verification for all domains hosted on this server
                foreach ($server->accounts as $account) {
                    foreach ($account->domains as $domain) {
                        $verificationService->verify($domain);
                        $domainsReverified++;
                    }
                }
            }

            $totalDomainsReverified += $domainsReverified;

            $rows[] = [
                $server->id,
                $server->name,
                $publicIp ?? 'none',
                $edgeHost ?? 'none',
                $changed ? 'Updated' : 'Unchanged',
                $domainsReverified,
            ];
        }

        $this->table(
            ['ID', 'Name', 'Public IP', 'Edge Host', 'Status', 'Re-verified Domains'],
            $rows
        );

        $this->info("Backfill complete. {$totalDomainsReverified} domain(s) re-verified.");

        return self::SUCCESS;
    }
}
