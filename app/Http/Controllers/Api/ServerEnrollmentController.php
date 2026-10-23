<?php

namespace App\Http\Controllers\Api;

use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerEnrollmentToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerEnrollmentController extends Controller
{
    /**
     * Enroll a new Go server agent node using a one-time enrollment token.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'hostname' => ['required', 'string', 'max:255', 'unique:servers,hostname'],
            'private_ip' => ['required', 'ip', 'unique:servers,private_ip'],
            'agent_url' => ['required', 'url', 'max:255'],
            'agent_public_cert_fingerprint' => ['nullable', 'string', 'max:128'],
            'cpu_cores' => ['nullable', 'integer', 'min:1'],
            'memory_mb' => ['nullable', 'integer', 'min:512'],
            'storage_mb' => ['nullable', 'integer', 'min:1024'],
            'location' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var ServerEnrollmentToken|null $enrollmentToken */
        $enrollmentToken = ServerEnrollmentToken::with('serverGroup')
            ->where('token', $validated['token'])
            ->first();

        if (! $enrollmentToken || ! $enrollmentToken->isValid()) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_TOKEN',
                    'message' => 'The provided server enrollment token is invalid, expired, or has already been used.',
                ],
            ], 422);
        }

        $location = $validated['location'] ?? ($enrollmentToken->serverGroup->location ?? 'US-East');

        /** @var Server $server */
        $server = Server::create([
            'name' => $enrollmentToken->server_name,
            'hostname' => $validated['hostname'],
            'private_ip' => $validated['private_ip'],
            'agent_url' => $validated['agent_url'],
            'status' => ServerStatus::ACTIVE,
            'agent_public_cert_fingerprint' => $validated['agent_public_cert_fingerprint'] ?? null,
            'server_group_id' => $enrollmentToken->server_group_id,
            'location' => $location,
            'cpu_cores' => $validated['cpu_cores'] ?? null,
            'memory_mb' => $validated['memory_mb'] ?? null,
            'storage_mb' => $validated['storage_mb'] ?? null,
        ]);

        $enrollmentToken->update([
            'used_at' => now(),
            'used_by_ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Agent node enrolled and activated successfully.',
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'private_ip' => $server->private_ip,
                'status' => $server->status->value,
                'location' => $server->location,
            ],
        ], 201);
    }
}
