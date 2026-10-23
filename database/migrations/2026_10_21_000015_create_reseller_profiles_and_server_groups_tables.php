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
        Schema::create('reseller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('max_accounts')->default(50);
            $table->unsignedBigInteger('max_storage_mb')->default(102400); // 100 GB
            $table->unsignedInteger('max_bandwidth_gb')->default(1000);   // 1 TB
            $table->string('company_name')->default('Cloud Hosting');
            $table->string('custom_domain')->nullable()->unique();
            $table->string('logo_url')->nullable();
            $table->string('support_email')->nullable();
            $table->timestamps();
        });

        Schema::create('server_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('location')->default('US-East');
            $table->timestamps();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('server_group_id')->nullable()->constrained('server_groups')->nullOnDelete();
            $table->string('location')->default('US-East');
        });

        Schema::create('server_enrollment_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('server_name');
            $table->foreignId('server_group_id')->nullable()->constrained('server_groups')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('used_by_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_enrollment_tokens');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropForeign(['server_group_id']);
            $table->dropColumn(['server_group_id', 'location']);
        });

        Schema::dropIfExists('server_groups');
        Schema::dropIfExists('reseller_profiles');
    }
};
