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
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('type')->default('primary');
            $table->string('document_root');
            $table->string('app_profile')->default('php-generic');
            $table->string('php_version', 10)->default('8.3');
            $table->boolean('force_https')->default(false);
            $table->string('www_redirect')->default('none');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['account_id', 'type']);
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('source_path');
            $table->string('target_url');
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('domain_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('domains');
    }
};
