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
        Schema::create('usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->decimal('storage_mb', 12, 2)->default(0);
            $table->unsignedBigInteger('inodes')->default(0);
            $table->unsignedBigInteger('bandwidth_bytes')->default(0);
            $table->unsignedBigInteger('database_bytes')->default(0);
            $table->unsignedInteger('fpm_active_workers')->default(0);
            $table->unsignedInteger('fpm_idle_workers')->default(0);
            $table->unsignedInteger('fpm_total_workers')->default(0);
            $table->json('raw_metrics')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->index(['account_id', 'recorded_at']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('current_storage_mb', 12, 2)->default(0);
            $table->unsignedBigInteger('current_inodes')->default(0);
            $table->unsignedBigInteger('current_bandwidth_bytes')->default(0);
            $table->timestamp('bandwidth_reset_at')->nullable();
            $table->json('log_offsets')->nullable();
            $table->unsignedTinyInteger('usage_warning_level')->default(0);
            $table->boolean('is_throttled')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'current_storage_mb',
                'current_inodes',
                'current_bandwidth_bytes',
                'bandwidth_reset_at',
                'log_offsets',
                'usage_warning_level',
                'is_throttled',
            ]);
        });

        Schema::dropIfExists('usage_snapshots');
    }
};
