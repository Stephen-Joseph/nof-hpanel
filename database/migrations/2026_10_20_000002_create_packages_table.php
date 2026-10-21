<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('storage_mb')->default(10240);
            $table->unsignedBigInteger('inodes')->default(100000);
            $table->unsignedInteger('bandwidth_gb')->default(100);
            $table->unsignedInteger('max_websites')->default(1);
            $table->unsignedInteger('max_subdomains')->default(5);
            $table->unsignedInteger('max_databases')->default(5);
            $table->unsignedInteger('max_db_users')->default(5);
            $table->unsignedInteger('max_ftp_accounts')->default(5);
            $table->unsignedInteger('max_cron_jobs')->default(5);
            $table->unsignedInteger('php_workers')->default(5);
            $table->unsignedInteger('php_memory_mb')->default(256);
            $table->json('allowed_php_versions');
            $table->unsignedInteger('backups_retained')->default(3);
            $table->boolean('ssh_enabled')->default(false);
            $table->boolean('git_enabled')->default(false);
            $table->foreignId('reseller_owner_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
