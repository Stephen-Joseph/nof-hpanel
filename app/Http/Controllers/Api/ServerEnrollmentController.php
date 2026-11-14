<?php

namespace App\Http\Controllers\Api;

use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerEnrollmentToken;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerEnrollmentController extends Controller
{
    /**
     * Enroll a new Go server agent node using a one-time enrollment token.
     */
    public function __invoke(Request $request, AgentClient $client): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'hostname' => ['required', 'string', 'max:255', 'unique:servers,hostname'],
            'private_ip' => ['required', 'ip', 'unique:servers,private_ip'],
            'public_ip' => ['nullable', 'string', 'max:255'],
            'agent_url' => ['required', 'url', 'max:255'],
            'agent_public_cert_fingerprint' => ['nullable', 'string', 'max:128'],
            'cpu_cores' => ['nullable', 'integer', 'min:1'],
            'memory_mb' => ['nullable', 'integer', 'min:512'],
            'storage_mb' => ['nullable', 'integer', 'min:1024'],
            'location' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var ServerEnrollmentToken|null $enrollmentToken */
        $enrollmentToken = ServerEnrollmentToken::findByRawToken($validated['token']);

        if (! $enrollmentToken || ! $enrollmentToken->isValid()) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_TOKEN',
                    'message' => 'The provided server enrollment token is invalid, expired, or has already been used.',
                ],
            ], 403);
        }

        $location = $validated['location'] ?? ($enrollmentToken->serverGroup->location ?? 'US-East');
        $publicIp = $validated['public_ip'] ?? $request->ip();
        $hmacSecret = bin2hex(random_bytes(32)); // 64 hex chars = 32 bytes

        /** @var Server $server */
        $server = Server::create([
            'name' => $enrollmentToken->server_name,
            'hostname' => $validated['hostname'],
            'private_ip' => $validated['private_ip'],
            'public_ip' => $publicIp,
            'agent_url' => $validated['agent_url'],
            'agent_hmac_secret' => $hmacSecret,
            'status' => ServerStatus::OFFLINE, // Set to ACTIVE only after health check passes
            'agent_public_cert_fingerprint' => $validated['agent_public_cert_fingerprint'] ?? null,
            'server_group_id' => $enrollmentToken->server_group_id,
            'location' => $location,
            'cpu_cores' => $validated['cpu_cores'] ?? null,
            'memory_mb' => $validated['memory_mb'] ?? null,
            'storage_mb' => $validated['storage_mb'] ?? null,
        ]);

        // Attempt live health check from panel to verify the agent responds
        $healthPassed = false;
        try {
            $healthRes = $client->health($server);
            if (! empty($healthRes['success'])) {
                $healthPassed = true;
                $server->update(['status' => ServerStatus::ACTIVE]);
            }
        } catch (\Throwable $e) {
            // Keep status OFFLINE if health check fails or agent not yet reachable
            $healthPassed = false;
        }

        // Mark enrollment token as used
        $enrollmentToken->update([
            'used_at' => now(),
            'used_by_ip' => $request->ip(),
        ]);

        // Resolve CA certificate to send to the node
        $caPath = config('services.agent.tls_ca_file');
        $caContent = '';
        if ($caPath && is_readable($caPath)) {
            $caContent = (string) file_get_contents($caPath);
        }
        if ($caContent === '') {
            $caContent = (string) config('hpanel.ca_cert', '');
        }

        return response()->json([
            'success' => true,
            'message' => 'Agent node enrolled successfully.',
            'ca_certificate' => $caContent,
            'hmac_secret' => $hmacSecret,
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'private_ip' => $server->private_ip,
                'public_ip' => $server->public_ip,
                'status' => $server->status->value,
                'location' => $server->location,
                'health_check_passed' => $healthPassed,
            ],
        ], 201);
    }
}
