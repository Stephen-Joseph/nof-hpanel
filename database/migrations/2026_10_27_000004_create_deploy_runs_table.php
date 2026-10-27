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
        Schema::create('deploy_runs', function (Blueprint $table) {
            $table->id();
            $table->string('commit_sha', 40)->index();
            $table->string('commit_message', 500)->nullable();
            $table->string('author', 255)->nullable();
            $table->string('delivery_id', 100)->nullable()->index();
            $table->string('status', 30)->default('queued')->index(); // queued, running, success, rolled_back, failed
            $table->string('action', 30)->default('deploy')->index(); // deploy, rollback
            $table->string('failed_step', 100)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('log_tail')->nullable();
            $table->boolean('has_migrations')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deploy_runs');
    }
};

