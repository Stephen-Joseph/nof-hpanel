<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $packages = [
            [
                'name' => 'Starter Cloud',
                'storage_mb' => 5120, // 5GB
                'inodes' => 100000,
                'bandwidth_gb' => 50,
                'max_websites' => 1,
                'max_subdomains' => 5,
                'max_databases' => 2,
                'max_db_users' => 2,
                'max_ftp_accounts' => 2,
                'max_cron_jobs' => 2,
                'php_workers' => 5,
                'php_memory_mb' => 256,
                'allowed_php_versions' => ['8.2', '8.3', '8.4'],
                'backups_retained' => 2,
                'ssh_enabled' => false,
                'git_enabled' => false,
                'reseller_owner_id' => null,
            ],
            [
                'name' => 'Pro Business',
                'storage_mb' => 25600, // 25GB
                'inodes' => 300000,
                'bandwidth_gb' => 250,
                'max_websites' => 5,
                'max_subdomains' => 25,
                'max_databases' => 10,
                'max_db_users' => 10,
                'max_ftp_accounts' => 10,
                'max_cron_jobs' => 10,
                'php_workers' => 15,
                'php_memory_mb' => 512,
                'allowed_php_versions' => ['8.1', '8.2', '8.3', '8.4'],
                'backups_retained' => 7,
                'ssh_enabled' => true,
                'git_enabled' => true,
                'reseller_owner_id' => null,
            ],
            [
                'name' => 'Enterprise Performance',
                'storage_mb' => 102400, // 100GB
                'inodes' => 1000000,
                'bandwidth_gb' => 1000,
                'max_websites' => 25,
                'max_subdomains' => 100,
                'max_databases' => 50,
                'max_db_users' => 50,
                'max_ftp_accounts' => 25,
                'max_cron_jobs' => 25,
                'php_workers' => 30,
                'php_memory_mb' => 1024,
                'allowed_php_versions' => ['8.1', '8.2', '8.3', '8.4'],
                'backups_retained' => 14,
                'ssh_enabled' => true,
                'git_enabled' => true,
                'reseller_owner_id' => null,
            ],
        ];

        foreach ($packages as $pkg) {
            Package::firstOrCreate(['name' => $pkg['name']], $pkg);
        }
    }
}
