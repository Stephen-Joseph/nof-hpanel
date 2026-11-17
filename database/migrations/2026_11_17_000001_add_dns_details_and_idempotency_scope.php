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
        Schema::table('domains', function (Blueprint $table) {
            if (! Schema::hasColumn('domains', 'dns_details')) {
                $table->json('dns_details')->nullable()->after('dns_last_error');
            }
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            try {
                $table->dropUnique(['user_id', 'key']);
            } catch (\Throwable) {
                // Ignore if unique index name differs or not present
            }
            $table->unique(['user_id', 'endpoint', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            try {
                $table->dropUnique(['user_id', 'endpoint', 'key']);
            } catch (\Throwable) {
            }
            $table->unique(['user_id', 'key']);
        });

        Schema::table('domains', function (Blueprint $table) {
            if (Schema::hasColumn('domains', 'dns_details')) {
                $table->dropColumn('dns_details');
            }
        });
    }
};
