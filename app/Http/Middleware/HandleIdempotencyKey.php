<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleIdempotencyKey
{
    /**
     * Handle an incoming request with Idempotency-Key header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        // Only enforce for mutating POST/PUT/PATCH/DELETE requests
        if (! $idempotencyKey || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $requestHash = hash('sha256', $request->getContent());

        /** @var IdempotencyKey|null $existing */
        $existing = IdempotencyKey::where('user_id', $user->id)
            ->where('key', $idempotencyKey)
            ->first();

        if ($existing) {
            // Verify payload matches
            if ($existing->request_hash !== $requestHash) {
                return response()->json([
                    'error' => [
                        'code' => 'IDEMPOTENCY_CONFLICT',
                        'message' => 'Idempotency key has already been used with a different request payload.',
                    ],
                ], 409);
            }

            // Return cached response
            $headers = $existing->response_headers ?? [];
            $headers['X-Idempotent-Replay'] = 'true';

            return response()->json(
                $existing->response_body,
                $existing->response_status,
                $headers
            );
        }

        $response = $next($request);

        // Cache successful or accepted responses (2xx)
        if ($response->isSuccessful() || $response->getStatusCode() === 202) {
            $responseBody = json_decode($response->getContent(), true);

            IdempotencyKey::create([
                'user_id' => $user->id,
                'key' => $idempotencyKey,
                'endpoint' => $request->path(),
                'request_hash' => $requestHash,
                'response_status' => $response->getStatusCode(),
                'response_headers' => ['Content-Type' => 'application/json'],
                'response_body' => is_array($responseBody) ? $responseBody : ['data' => $responseBody],
                'expires_at' => now()->addHours(24),
            ]);
        }

        return $response;
    }
}
