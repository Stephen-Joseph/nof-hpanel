<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Console\Command;

class RegisterLocalNodeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hpanel:register-local-node
        {--name= : Display name for the server node}
        {--public-ip= : Public IP address(es) of the node}
        {--agent-url=https://127.0.0.1:8443 : Agent URL}
        {--private-ip= : Private/internal IP address}
        {--hostname= : Server hostname}
        {--cert-fingerprint= : TLS certificate SHA-256 fingerprint}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register or update the local hosting node on this server without requiring manual token exchange';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = trim((string) ($this->option('name') ?: ''));
        if ($name === '') {
            $name = (string) (gethostname() ?: 'local-node');
        }

        $publicIp = trim((string) ($this->option('public-ip') ?: ''));
        if ($publicIp === '') {
            $this->error('The --public-ip option is required.');

            return self::FAILURE;
        }

        $agentUrl = trim((string) ($this->option('agent-url') ?: 'https://127.0.0.1:8443'));

        $privateIp = trim((string) ($this->option('private-ip') ?: ''));
        if ($privateIp === '') {
            $detected = gethostbyname((string) gethostname());
            $privateIp = filter_var($detected, FILTER_VALIDATE_IP) ? $detected : '127.0.0.1';
        }

        $hostname = trim((string) ($this->option('hostname') ?: ''));
        if ($hostname === '') {
            $hostname = (string) (gethostname() ?: 'localhost');
        }

        $certFingerprint = trim((string) ($this->option('cert-fingerprint') ?: ''));
        if ($certFingerprint === '') {
            $certPath = '/etc/panel-agent/certs/server.crt';
            if (file_exists($certPath)) {
                $certContent = @file_get_contents($certPath);
                if ($certContent !== false) {
                    $x509 = openssl_x509_read($certContent);
                    if ($x509) {
                        $fp = openssl_x509_fingerprint($x509, 'sha256');
                        if ($fp) {
                            $certFingerprint = strtolower($fp);
                        }
                    }
                }
            }
        }

        // Match existing server by agent_url or name first (idempotent registration)
        /** @var Server|null $server */
        $server = Server::where('agent_url', $agentUrl)
            ->orWhere('name', $name)
            ->first();

        if (! $server) {
            $server = Server::where('hostname', $hostname)
                ->orWhere('private_ip', $privateIp)
                ->first();
        }

        if ($server) {
            $updateData = [
                'name' => $name,
                'public_ip' => $publicIp,
                'agent_url' => $agentUrl,
                'status' => ServerStatus::ACTIVE,
            ];

            if ($certFingerprint !== '') {
                $updateData['agent_public_cert_fingerprint'] = $certFingerprint;
            }

            if (! Server::where('id', '!=', $server->id)->where('hostname', $hostname)->exists()) {
                $updateData['hostname'] = $hostname;
            }

            if (! Server::where('id', '!=', $server->id)->where('private_ip', $privateIp)->exists()) {
                $updateData['private_ip'] = $privateIp;
            }

            $server->update($updateData);

            $this->info("Local node '{$server->name}' (ID: {$server->id}, Status: active) updated successfully.");
        } else {
            if (Server::where('hostname', $hostname)->exists()) {
                $hostname = $hostname.'-'.substr(md5((string) microtime(true)), 0, 4);
            }

            $createData = [
                'name' => $name,
                'hostname' => $hostname,
                'private_ip' => $privateIp,
                'public_ip' => $publicIp,
                'agent_url' => $agentUrl,
                'status' => ServerStatus::ACTIVE,
                'max_accounts' => 100,
                'location' => 'Local',
            ];

            if ($certFingerprint !== '') {
                $createData['agent_public_cert_fingerprint'] = $certFingerprint;
            }

            $server = Server::create($createData);

            $this->info("Local node '{$server->name}' (ID: {$server->id}, Status: active) registered successfully.");
        }

        return self::SUCCESS;
    }
}

