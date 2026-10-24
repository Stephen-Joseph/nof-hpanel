<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $startTime = microtime(true);

        // If already authenticated by outer api.auth, perform scope check only
        if ($request->attributes->has('api_token')) {
            /** @var ApiToken $apiToken */
            $apiToken = $request->attributes->get('api_token');
            if ($scope !== null) {
                $allowedScopes = explode('|', $scope);
                $hasScope = false;
                foreach ($allowedScopes as $s) {
                    if ($apiToken->can($s)) {
                        $hasScope = true;
                        break;
                    }
                }

                if (! $hasScope) {
                    $this->logRequest($request, 403, $apiToken, $startTime);

                    return response()->json([
                        'error' => [
                            'code' => 'INSUFFICIENT_SCOPE',
                            'message' => "This API token does not have the required '{$scope}' scope.",
                        ],
                    ], 403);
                }
            }

            return $next($request);
        }

        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            $this->logRequest($request, 401, null, $startTime);

            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Missing or invalid Bearer token in Authorization header.',
                ],
            ], 401);
        }

        $plainTextToken = trim(substr($header, 7));
        $tokenHash = hash('sha256', $plainTextToken);

        /** @var ApiToken|null $apiToken */
        $apiToken = ApiToken::with('user')->where('token_hash', $tokenHash)->first();

        if (! $apiToken) {
            $this->logRequest($request, 401, null, $startTime);

            return response()->json([
                'error' => [
                    'code' => 'INVALID_TOKEN',
                    'message' => 'The provided API token is invalid.',
                ],
            ], 401);
        }

        if ($apiToken->isRevoked()) {
            $this->logRequest($request, 401, $apiToken, $startTime);

            return response()->json([
                'error' => [
                    'code' => 'TOKEN_REVOKED',
                    'message' => 'This API token has been revoked.',
                ],
            ], 401);
        }

        if ($apiToken->isExpired()) {
            $this->logRequest($request, 401, $apiToken, $startTime);

            return response()->json([
                'error' => [
                    'code' => 'TOKEN_EXPIRED',
                    'message' => 'This API token has expired.',
                ],
            ], 401);
        }

        // Validate IP allowlist if configured
        if (! $apiToken->isValidForIp($request->ip())) {
            $this->logRequest($request, 403, $apiToken, $startTime);

            return response()->json([
                'error' => [
                    'code' => 'IP_NOT_ALLOWED',
                    'message' => 'Requests from your IP address are not permitted for this API token.',
                ],
            ], 403);
        }

        // Validate scope if required
        if ($scope !== null) {
            $allowedScopes = explode('|', $scope);
            $hasScope = false;
            foreach ($allowedScopes as $s) {
                if ($apiToken->can($s)) {
                    $hasScope = true;
                    break;
                }
            }

            if (! $hasScope) {
                $this->logRequest($request, 403, $apiToken, $startTime);

                return response()->json([
                    'error' => [
                        'code' => 'INSUFFICIENT_SCOPE',
                        'message' => "This API token does not have the required '{$scope}' scope.",
                    ],
                ], 403);
            }
        }

        // Authenticate the user on the request
        $request->setUserResolver(fn () => $apiToken->user);
        $request->attributes->set('api_token', $apiToken);

        // Update token timestamp non-blockingly
        $apiToken->updateQuietly(['last_used_at' => now()]);

        $response = $next($request);

        $this->logRequest($request, $response->getStatusCode(), $apiToken, $startTime);

        return $response;
    }

    protected function logRequest(Request $request, int $statusCode, ?ApiToken $token, float $startTime): void
    {
        try {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            \App\Models\ApiRequestLog::create([
                'user_id' => $token?->user_id,
                'api_token_id' => $token?->id,
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status_code' => $statusCode,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'duration_ms' => $durationMs,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Logging failure should not disrupt the API response
        }
    }
}
