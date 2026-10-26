<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('verification_token', 64)->nullable()->unique()->after('domain');
            $table->string('verification_status', 32)->default('pending_dns')->after('verification_token');
            $table->timestamp('verified_at')->nullable()->after('verification_status');
            $table->timestamp('dns_last_checked_at')->nullable()->after('verified_at');
            $table->string('dns_last_error', 500)->nullable()->after('dns_last_checked_at');
            $table->unsignedInteger('dns_consecutive_failures')->default(0)->after('dns_last_error');
            $table->boolean('is_apex')->default(false)->after('dns_consecutive_failures');
            $table->string('ssl_status', 32)->default('none')->after('is_apex');
            $table->string('ssl_last_error', 500)->nullable()->after('ssl_status');
            $table->timestamp('ssl_expires_at')->nullable()->after('ssl_last_error');
            $table->timestamp('ssl_requested_at')->nullable()->after('ssl_expires_at');
        });

        // Ensure any existing domain rows receive a unique 32-hex verification token
        $existing = DB::table('domains')->whereNull('verification_token')->get(['id', 'domain', 'type']);
        foreach ($existing as $domain) {
            $isApex = ! str_contains($domain->domain, '.') || count(explode('.', $domain->domain)) <= 2;
            DB::table('domains')->where('id', $domain->id)->update([
                'verification_token' => bin2hex(random_bytes(16)),
                'is_apex' => $isApex,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'verification_token',
                'verification_status',
                'verified_at',
                'dns_last_checked_at',
                'dns_last_error',
                'dns_consecutive_failures',
                'is_apex',
                'ssl_status',
                'ssl_last_error',
                'ssl_expires_at',
                'ssl_requested_at',
            ]);
        });
    }
};

