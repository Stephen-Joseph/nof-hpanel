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
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'public_ipv6')) {
                $table->string('public_ipv6', 64)->nullable()->after('public_ip');
            }
            if (! Schema::hasColumn('servers', 'edge_host')) {
                $table->string('edge_host', 255)->nullable()->unique()->after('public_ipv6');
            }
            if (! Schema::hasColumn('servers', 'edge_dns_checked_at')) {
                $table->timestamp('edge_dns_checked_at')->nullable()->after('edge_host');
            }
            if (! Schema::hasColumn('servers', 'edge_dns_ok')) {
                $table->boolean('edge_dns_ok')->default(false)->after('edge_dns_checked_at');
            }
        });

        // Backfill node 1: edge_host = current HPANEL_EDGE_HOST, public_ip = host IP of agent_url or fallback
        $firstServer = DB::table('servers')->orderBy('id')->first();
        if ($firstServer) {
            $edgeHost = (string) config('hpanel.edge_host', 'edge.nexorafox.in');
            $updates = [];
            if (empty($firstServer->edge_host)) {
                $updates['edge_host'] = $edgeHost;
            }
            if (empty($firstServer->public_ip)) {
                $host = parse_url($firstServer->agent_url ?? '', PHP_URL_HOST);
                $updates['public_ip'] = ! empty($host) ? $host : '198.51.100.1';
            }
            if (! empty($updates)) {
                DB::table('servers')->where('id', $firstServer->id)->update($updates);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'edge_dns_ok')) {
                $table->dropColumn('edge_dns_ok');
            }
            if (Schema::hasColumn('servers', 'edge_dns_checked_at')) {
                $table->dropColumn('edge_dns_checked_at');
            }
            if (Schema::hasColumn('servers', 'edge_host')) {
                $table->dropColumn('edge_host');
            }
            if (Schema::hasColumn('servers', 'public_ipv6')) {
                $table->dropColumn('public_ipv6');
            }
        });
    }
};
