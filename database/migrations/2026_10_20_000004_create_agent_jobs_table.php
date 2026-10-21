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
        Schema::create('agent_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('status')->default('pending');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('action');
        });

        Schema::create('agent_job_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('agent_job_id')->constrained('agent_jobs')->cascadeOnDelete();
            $table->string('level')->default('info');
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agent_job_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_job_logs');
        Schema::dropIfExists('agent_jobs');
    }
};
