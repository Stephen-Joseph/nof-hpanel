<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(AgentClient $client): JsonResponse
    {
        $databaseStatus = 'unknown';
        try {
            DB::connection()->getPdo();
            $databaseStatus = 'connected';
        } catch (\Throwable $e) {
            $databaseStatus = 'error: '.$e->getMessage();
        }

        $servers = Server::where('status', 'active')->get();
        $serversHealth = [];
        $allAgentsHealthy = true;

        foreach ($servers as $server) {
            try {
                $ping = $client->ping($server);
                $serversHealth[] = [
                    'id' => $server->id,
                    'name' => $server->name,
                    'hostname' => $server->hostname,
                    'status' => $ping['success'] ? 'online' : 'error',
                    'agent_version' => $ping['data']['agent_version'] ?? 'unknown',
                ];
                if (! $ping['success']) {
                    $allAgentsHealthy = false;
                }
            } catch (\Throwable $e) {
                $serversHealth[] = [
                    'id' => $server->id,
                    'name' => $server->name,
                    'hostname' => $server->hostname,
                    'status' => 'offline',
                    'error' => $e->getMessage(),
                ];
                $allAgentsHealthy = false;
            }
        }

        $overallStatus = ($databaseStatus === 'connected' && $allAgentsHealthy) ? 'healthy' : 'degraded';

        return response()->json([
            'status' => $overallStatus,
            'panel' => [
                'status' => 'ok',
                'database' => $databaseStatus,
                'timestamp' => now()->toIso8601String(),
            ],
            'servers' => $serversHealth,
        ]);
    }
}
