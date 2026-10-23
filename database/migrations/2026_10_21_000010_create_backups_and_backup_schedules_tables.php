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
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('snapshot_id', 64)->nullable()->index();
            $table->string('backup_type', 32)->default('manual');
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->decimal('duration_seconds', 8, 2)->default(0);
            $table->json('databases_included')->nullable();
            $table->text('error_message')->nullable();
            $table->string('download_path')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status', 'created_at']);
        });

        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('frequency', 32)->default('daily');
            $table->string('time_of_day', 5)->default('02:00');
            $table->unsignedTinyInteger('day_of_week')->default(0);
            $table->unsignedTinyInteger('day_of_month')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->unique('account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_schedules');
        Schema::dropIfExists('backups');
    }
};
