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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->foreignId('package_id')->index()->constrained('packages')->restrictOnDelete();
            $table->foreignId('server_id')->index()->constrained('servers')->restrictOnDelete();
            $table->string('linux_username', 16)->unique();
            $table->string('primary_domain')->index();
            $table->string('status')->default('pending')->index();
            $table->string('suspended_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
