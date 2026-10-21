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
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('type')->default('lets_encrypt');
            $table->string('status')->default('pending');
            $table->string('cert_path')->nullable();
            $table->string('key_path')->nullable();
            $table->string('issuer')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->boolean('hsts_enabled')->default(false);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['domain_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
