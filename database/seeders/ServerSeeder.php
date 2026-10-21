<?php

namespace Database\Seeders;

use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Database\Seeder;

class ServerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Server::firstOrCreate(
            ['hostname' => 'node1.hpanel.local'],
            [
                'name' => 'Primary Production Node (Frankfurt)',
                'private_ip' => '10.100.0.1',
                'agent_url' => 'https://10.100.0.1:8443',
                'status' => ServerStatus::ACTIVE,
                'agent_public_cert_fingerprint' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                'max_accounts' => 150,
                'cpu_cores' => 16,
                'memory_mb' => 32768,
                'storage_mb' => 1000000,
            ]
        );
    }
}
