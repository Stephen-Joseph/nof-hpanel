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
        if (! Schema::hasColumn('api_tokens', 'prefix')) {
            Schema::table('api_tokens', function (Blueprint $table) {
                $table->string('prefix', 16)->nullable()->after('token_hash');
            });
        }

        if (! Schema::hasTable('api_request_logs')) {
            Schema::create('api_request_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
                $table->string('method', 10);
                $table->string('path', 255);
                $table->integer('status_code');
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['user_id', 'created_at']);
                $table->index(['api_token_id', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');

        if (Schema::hasColumn('api_tokens', 'prefix')) {
            Schema::table('api_tokens', function (Blueprint $table) {
                $table->dropColumn('prefix');
            });
        }
    }
};
