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
        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'target_id')) {
            $type = Schema::getColumnType('audit_logs', 'target_id');
            if ($type !== 'string') {
                Schema::table('audit_logs', function (Blueprint $table) {
                    $table->string('target_id', 64)->nullable()->change();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'target_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('target_id')->nullable()->change();
            });
        }
    }
};
