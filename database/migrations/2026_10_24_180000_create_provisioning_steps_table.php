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
        Schema::create('provisioning_steps', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->index();
            $table->string('key');
            $table->string('label');
            $table->enum('status', ['pending', 'running', 'done', 'failed'])->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            // Foreign key to agent_jobs.id (UUID string)
            $table->foreign('job_id')->references('id')->on('agent_jobs')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provisioning_steps');
    }
};
