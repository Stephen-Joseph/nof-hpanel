<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\AgentClient;
use Illuminate\Console\Command;
use Throwable;

class AgentDispatchCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hpanel:agent-dispatch
        {action : The typed action name (e.g. account.create, account.terminate)}
        {--payload={} : JSON string payload for the action}
        {--server= : ID or hostname of the server}
        {--url= : Optional direct agent URL}
        {--dry-run : Execute action in dry-run mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute a typed action against a server agent via AgentClient';

    /**
     * Execute the console command.
     */
    public function handle(AgentClient $client): int
    {
        $action = (string) $this->argument('action');
        $rawPayload = (string) ($this->option('payload') ?: '{}');

        $payload = json_decode($rawPayload, true);
        if (! is_array($payload)) {
            $this->error("Invalid JSON payload provided: {$rawPayload}");

            return self::FAILURE;
        }

        $serverOption = $this->option('server');
        $urlOption = $this->option('url');

        $server = null;
        if ($serverOption) {
            $server = Server::where('id', $serverOption)
                ->orWhere('hostname', $serverOption)
                ->orWhere('name', $serverOption)
                ->first();

            if (! $server) {
                $this->error("Server not found matching: {$serverOption}");

                return self::FAILURE;
            }
        } elseif ($urlOption) {
            $server = new Server([
                'name' => 'CLI Specified Node',
                'hostname' => parse_url($urlOption, PHP_URL_HOST) ?: '127.0.0.1',
                'agent_url' => $urlOption,
            ]);
        } else {
            $server = Server::where('agent_url', 'like', '%127.0.0.1%')
                ->orWhere('agent_url', 'like', '%localhost%')
                ->first() ?? Server::first();

            if (! $server) {
                $server = new Server([
                    'name' => 'Local Default Node',
                    'hostname' => '127.0.0.1',
                    'agent_url' => 'https://127.0.0.1:8443',
                ]);
            }
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $response = $client->execute($server, $action, $payload, $dryRun);
            $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Agent action failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
