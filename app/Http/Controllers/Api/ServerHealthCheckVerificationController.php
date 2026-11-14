<?php

namespace App\Http\Controllers\Api;

use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ServerHealthCheckVerificationController extends Controller
{
    /**
     * Trigger a live agent health check from the panel to verify node connectivity.
     */
    public function __invoke(Request $request, AgentClient $client): JsonResponse
    {
        $validated = $request->validate([
            'server_id' => ['required', 'integer', 'exists:servers,id'],
            'hmac_secret' => ['required', 'string', 'min:16'],
        ]);

        /** @var Server $server */
        $server = Server::findOrFail($validated['server_id']);

        if (! $server->agent_hmac_secret || ! hash_equals((string) $server->agent_hmac_secret, (string) $validated['hmac_secret'])) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'Invalid server credentials provided for health check verification.',
                ],
            ], 403);
        }

        try {
            $result = $client->health($server);
            if (! empty($result['success'])) {
                $server->update(['status' => ServerStatus::ACTIVE]);

                return response()->json([
                    'success' => true,
                    'status' => 'active',
                    'message' => 'Agent health check verified successfully by panel.',
                    'server' => [
                        'id' => $server->id,
                        'name' => $server->name,
                        'status' => $server->status->value,
                    ],
                ]);
            }

            return response()->json([
                'error' => [
                    'code' => 'HEALTH_CHECK_FAILED',
                    'message' => 'Agent returned unsuccessful health check response.',
                ],
            ], 502);
        } catch (Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'HEALTH_CHECK_FAILED',
                    'message' => 'Panel failed to reach agent health check: '.$e->getMessage(),
                ],
            ], 502);
        }
    }
}
