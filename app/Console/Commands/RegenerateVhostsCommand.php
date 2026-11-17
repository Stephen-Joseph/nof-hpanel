<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Server;
use App\Services\AgentClient;
use Illuminate\Console\Command;

class RegenerateVhostsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hpanel:regenerate-vhosts
                            {--server= : Optional server ID to target}
                            {--domain= : Optional single domain to regenerate}
                            {--dry-run : Simulate regeneration without writing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-renders all vhosts using the standardized ACME challenge layout while preserving custom configuration blocks.';

    /**
     * Execute the console command.
     */
    public function handle(AgentClient $client): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $serverId = $this->option('server');
        $domainFilter = $this->option('domain');

        $this->info('Starting vhost regeneration...');

        $serversQuery = Server::query()->where('status', 'active');
        if ($serverId) {
            $serversQuery->where('id', $serverId);
        }
        $servers = $serversQuery->get();

        if ($servers->isEmpty()) {
            $this->warn('No active servers found to regenerate vhosts on.');
            return Command::SUCCESS;
        }

        $totalRegenerated = 0;

        foreach ($servers as $server) {
            $domainsQuery = Domain::query()
                ->whereHas('account', function ($q) use ($server) {
                    $q->where('server_id', $server->id)
                      ->where('status', 'active');
                })
                ->with(['account', 'certificate', 'redirects', 'protectedDirectories']);

            if ($domainFilter) {
                $domainsQuery->where('domain', $domainFilter);
            }

            $domains = $domainsQuery->get();

            if ($domains->isEmpty()) {
                $this->line(sprintf('Server %s (#%d): No domains to regenerate.', $server->name, $server->id));
                continue;
            }

            $this->info(sprintf('Server %s (#%d): Preparing %d domain vhosts...', $server->name, $server->id, $domains->count()));

            $vhostItems = [];
            foreach ($domains as $domain) {
                $account = $domain->account;
                $redirects = $domain->redirects
                    ->where('is_active', true)
                    ->map(fn ($r) => [
                        'source' => $r->source_path,
                        'target' => $r->target_url,
                        'code' => $r->status_code,
                    ])
                    ->values()
                    ->toArray();

                $protectedDirs = $domain->protectedDirectories
                    ->where('is_active', true)
                    ->map(fn ($p) => [
                        'path' => $p->path,
                        'realm' => $p->realm,
                        'htpasswd_path' => "/home/{$account->linux_username}/.htpasswds/{$p->htpasswd_filename}",
                    ])
                    ->values()
                    ->toArray();

                $appProfile = $domain->app_profile instanceof \BackedEnum
                    ? $domain->app_profile->value
                    : ($domain->app_profile ?? 'php-generic');

                $wwwRedirect = $domain->www_redirect instanceof \BackedEnum
                    ? $domain->www_redirect->value
                    : ($domain->www_redirect ?? 'none');

                $hasSsl = (bool) ($domain->ssl_status === 'active' || !empty($domain->certificate?->cert_path));
                $certPath = $domain->certificate?->cert_path ?? "/etc/letsencrypt/live/{$domain->domain}/fullchain.pem";
                $keyPath = $domain->certificate?->key_path ?? "/etc/letsencrypt/live/{$domain->domain}/privkey.pem";

                $vhostItems[] = [
                    'username' => $account->linux_username ?? $account->username,
                    'domain' => $domain->domain,
                    'server_names' => $domain->domain . ($domain->is_subdomain ? '' : " www.{$domain->domain}"),
                    'document_root' => $domain->document_root,
                    'app_profile' => $appProfile,
                    'php_version' => $domain->php_version ?? '8.3',
                    'force_https' => (bool) $domain->force_https,
                    'ssl_enabled' => $hasSsl,
                    'ssl_cert_path' => $certPath,
                    'ssl_key_path' => $keyPath,
                    'hsts_enabled' => (bool) ($domain->certificate?->hsts_enabled ?? false),
                    'www_redirect' => $wwwRedirect,
                    'redirects' => $redirects,
                    'protected_dirs' => $protectedDirs,
                ];
            }

            try {
                $response = $client->execute(
                    server: $server,
                    action: 'vhost.regenerate_all',
                    payload: ['vhosts' => $vhostItems],
                    dryRun: $dryRun
                );

                if ($response['success']) {
                    $count = count($vhostItems);
                    $totalRegenerated += $count;
                    $this->info(sprintf('Server %s (#%d): Successfully regenerated %d vhost(s).', $server->name, $server->id, $count));
                } else {
                    $this->error(sprintf('Server %s (#%d): Failed to regenerate vhosts: %s', $server->name, $server->id, $response['error'] ?? 'Unknown error'));
                }
            } catch (\Throwable $e) {
                $this->error(sprintf('Server %s (#%d): Exception while communicating with agent: %s', $server->name, $server->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('Vhost regeneration complete. Total regenerated: %d.', $totalRegenerated));

        return Command::SUCCESS;
    }
}
