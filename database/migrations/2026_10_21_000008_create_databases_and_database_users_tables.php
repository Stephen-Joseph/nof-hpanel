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
        Schema::create('databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('name', 64)->unique();
            $table->string('charset', 32)->default('utf8mb4');
            $table->string('collation', 64)->default('utf8mb4_unicode_ci');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->index('account_id');
        });

        Schema::create('database_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('username', 32);
            $table->string('remote_host', 64)->default('localhost');
            $table->integer('max_connections')->default(20);
            $table->timestamps();

            $table->unique(['username', 'remote_host']);
            $table->index('account_id');
        });

        Schema::create('database_user_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('database_id')->constrained('databases')->cascadeOnDelete();
            $table->foreignId('database_user_id')->constrained('database_users')->cascadeOnDelete();
            $table->json('privileges');
            $table->timestamps();

            $table->unique(['database_id', 'database_user_id']);
        });

        Schema::create('database_sso_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('database_user_id')->constrained('database_users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('target_db', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['token', 'expires_at', 'used_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_sso_tokens');
        Schema::dropIfExists('database_user_grants');
        Schema::dropIfExists('database_users');
        Schema::dropIfExists('databases');
    }
};
