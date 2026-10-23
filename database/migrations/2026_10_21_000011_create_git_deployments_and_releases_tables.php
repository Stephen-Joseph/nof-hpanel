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
        Schema::create('git_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->string('name');
            $table->string('repository_url');
            $table->string('branch')->default('main');
            $table->string('deploy_path');
            $table->text('public_key');
            $table->text('private_key');
            $table->string('webhook_secret')->unique();
            $table->json('build_steps')->nullable();
            $table->boolean('auto_deploy')->default(false);
            $table->timestamp('last_deployed_at')->nullable();
            $table->string('last_commit_hash', 64)->nullable();
            $table->timestamps();

            $table->index('account_id');
        });

        Schema::create('git_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('git_deployment_id')->constrained('git_deployments')->cascadeOnDelete();
            $table->string('release_tag');
            $table->string('commit_hash', 64)->nullable();
            $table->text('commit_message')->nullable();
            $table->string('committer')->nullable();
            $table->string('status')->default('pending');
            $table->longText('log_output')->nullable();
            $table->decimal('duration_seconds', 8, 2)->default(0);
            $table->timestamps();

            $table->index(['git_deployment_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('git_releases');
        Schema::dropIfExists('git_deployments');
    }
};
