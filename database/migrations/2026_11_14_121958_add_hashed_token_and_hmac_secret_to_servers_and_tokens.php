<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('server_enrollment_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('server_enrollment_tokens', 'token_hash')) {
                $table->string('token_hash', 64)->nullable()->unique()->after('id');
            }
            if (DB::getDriverName() !== 'sqlite') {
                $table->string('token', 64)->nullable()->change();
            }
        });

        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'agent_hmac_secret')) {
                $table->text('agent_hmac_secret')->nullable()->after('agent_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'agent_hmac_secret')) {
                $table->dropColumn('agent_hmac_secret');
            }
        });

        Schema::table('server_enrollment_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('server_enrollment_tokens', 'token_hash')) {
                $table->dropColumn('token_hash');
            }
        });
    }
};
