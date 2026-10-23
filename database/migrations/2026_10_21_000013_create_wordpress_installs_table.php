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
        Schema::create('wordpress_installs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('install_path');
            $table->string('site_title');
            $table->string('admin_email');
            $table->string('admin_user');
            $table->string('db_name');
            $table->string('db_user');
            $table->string('table_prefix', 20)->default('wp_');
            $table->string('locale', 20)->default('en_US');
            $table->string('version', 20)->nullable();
            $table->string('status')->default('installed');
            $table->timestamps();

            $table->index(['account_id', 'domain_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wordpress_installs');
    }
};
