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
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname')->unique();
            $table->string('private_ip', 45)->unique();
            $table->string('agent_url');
            $table->string('status')->default('active')->index();
            $table->string('agent_public_cert_fingerprint')->nullable();
            $table->unsignedInteger('max_accounts')->default(100);
            $table->unsignedTinyInteger('cpu_cores')->nullable();
            $table->unsignedInteger('memory_mb')->nullable();
            $table->unsignedBigInteger('storage_mb')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
