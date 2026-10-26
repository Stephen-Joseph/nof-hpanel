<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\AgentClient;
use App\Services\Exceptions\AgentClientException;
use App\Services\Exceptions\AgentConnectionException;
use App\Services\Exceptions\AgentSecurityException;
use Illuminate\Console\Command;
use Throwable;

class AgentCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hpanel:agent-check
        {--server= : ID or hostname of the server to check}
        {--url= : Optional direct agent URL to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dry-run ping the server agent and verify config paths, TLS certificates, and HMAC signature';

    /**
     * Execute the console command.
     */
    public function handle(AgentClient $client): int
    {
        $this->info('=== HPanel Agent Configuration & Connectivity Check ===');

        $hasErrors = false;

        // 1. Check Config Paths
        $secret = (string) config('services.agent.secret', '');
        $defaultSecret = 'test-agent-secret-key-change-me';
        $caFile = config('services.agent.tls_ca_file');
        $clientCert = config('services.agent.client_cert_file');
        $clientKey = config('services.agent.client_key_file');

        $configRows = [];

        // Secret check
        if ($secret === '') {
            $configRows[] = ['PANEL_AGENT_HMAC_SECRET', '(empty)', 'NO', 'FAIL: Secret is empty'];
            $hasErrors = true;
        } elseif ($secret === $defaultSecret) {
            $configRows[] = ['PANEL_AGENT_HMAC_SECRET', '(default secret)', 'YES', 'WARN: Using default insecure secret'];
            if (app()->environment('production')) {
                $hasErrors = true;
            }
        } else {
            $configRows[] = ['PANEL_AGENT_HMAC_SECRET', '(configured)', 'YES', 'OK'];
        }

        // TLS CA File
        if (! $caFile) {
            $configRows[] = ['PANEL_AGENT_TLS_CA_FILE', '(not set)', 'NO', 'WARN: Using system CA bundle'];
        } elseif (! file_exists($caFile)) {
            $configRows[] = ['PANEL_AGENT_TLS_CA_FILE', $caFile, 'NO', 'FAIL: File not found'];
            $hasErrors = true;
        } elseif (! is_readable($caFile)) {
            $configRows[] = ['PANEL_AGENT_TLS_CA_FILE', $caFile, 'NO (unreadable)', 'FAIL: Permission denied'];
            $hasErrors = true;
        } else {
            $configRows[] = ['PANEL_AGENT_TLS_CA_FILE', $caFile, 'YES', 'OK'];
        }

        // Client Cert File
        if (! $clientCert) {
            $configRows[] = ['PANEL_AGENT_CLIENT_CERT_FILE', '(not set)', 'NO', 'WARN: mTLS client cert not configured'];
        } elseif (! file_exists($clientCert)) {
            $configRows[] = ['PANEL_AGENT_CLIENT_CERT_FILE', $clientCert, 'NO', 'FAIL: File not found'];
            $hasErrors = true;
        } elseif (! is_readable($clientCert)) {
            $configRows[] = ['PANEL_AGENT_CLIENT_CERT_FILE', $clientCert, 'NO (unreadable)', 'FAIL: Permission denied'];
            $hasErrors = true;
        } else {
            $configRows[] = ['PANEL_AGENT_CLIENT_CERT_FILE', $clientCert, 'YES', 'OK'];
        }

        // Client Key File
        if (! $clientKey) {
            $configRows[] = ['PANEL_AGENT_CLIENT_KEY_FILE', '(not set)', 'NO', 'WARN: mTLS client key not configured'];
        } elseif (! file_exists($clientKey)) {
            $configRows[] = ['PANEL_AGENT_CLIENT_KEY_FILE', $clientKey, 'NO', 'FAIL: File not found'];
            $hasErrors = true;
        } elseif (! is_readable($clientKey)) {
            $configRows[] = ['PANEL_AGENT_CLIENT_KEY_FILE', $clientKey, 'NO (unreadable)', 'FAIL: Permission denied'];
            $hasErrors = true;
        } else {
            $configRows[] = ['PANEL_AGENT_CLIENT_KEY_FILE', $clientKey, 'YES', 'OK'];
        }

        $this->table(['Config Setting', 'Path / Value', 'Readable', 'Status'], $configRows);

        // 2. Resolve Server
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

        $this->line("Target Server: <comment>{$server->name}</comment> ({$server->agent_url})");

        // 3. Dry-run ping execution
        $tlsResult = 'FAIL';
        $hmacResult = 'FAIL';
        $pingDetails = '';

        try {
            $response = $client->execute(
                server: $server,
                action: 'system.ping',
                payload: [],
                dryRun: true,
                maxRetries: 1
            );

            if ($response['success']) {
                $tlsResult = 'PASS';
                $hmacResult = 'PASS';
                $pingDetails = 'Connected and authenticated successfully (system.ping responded pong=true)';
            } else {
                $tlsResult = 'PASS';
                $hmacResult = 'WARN';
                $pingDetails = 'Agent reached but returned unsuccessful response: '.json_encode($response['error']);
                $hasErrors = true;
            }
        } catch (AgentSecurityException $e) {
            $tlsResult = 'PASS';
            $hmacResult = 'FAIL';
            $pingDetails = 'HMAC authentication / signature verification failed: '.$e->getMessage();
            $hasErrors = true;
        } catch (AgentConnectionException $e) {
            $tlsResult = 'FAIL';
            $hmacResult = 'SKIPPED';
            $pingDetails = 'Connection / TLS handshake failed: '.$e->getMessage();
            $hasErrors = true;
        } catch (AgentClientException $e) {
            $tlsResult = 'PASS';
            $hmacResult = 'PASS';
            $pingDetails = 'Agent responded with client error: '.$e->getMessage();
            $hasErrors = true;
        } catch (Throwable $e) {
            $tlsResult = 'FAIL';
            $hmacResult = 'UNKNOWN';
            $pingDetails = 'Unexpected failure: '.$e->getMessage();
            $hasErrors = true;
        }

        $results = [
            ['TLS Connection & Handshake', $tlsResult],
            ['HMAC Request Signature', $hmacResult],
            ['Ping Result Details', $pingDetails],
        ];

        $this->newLine();
        $this->table(['Check', 'Result'], $results);

        if ($hasErrors) {
            $this->error('Agent check completed with errors.');

            return self::FAILURE;
        }

        $this->info('Agent check passed successfully.');

        return self::SUCCESS;
    }
}
