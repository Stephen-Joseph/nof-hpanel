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
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
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
            return response()->json([
                'error' => [
                    'code' => 'INVALID_TOKEN',
                    'message' => 'The provided API token is invalid.',
                ],
            ], 401);
        }

        if ($apiToken->isRevoked()) {
            return response()->json([
                'error' => [
                    'code' => 'TOKEN_REVOKED',
                    'message' => 'This API token has been revoked.',
                ],
            ], 401);
        }

        if ($apiToken->isExpired()) {
            return response()->json([
                'error' => [
                    'code' => 'TOKEN_EXPIRED',
                    'message' => 'This API token has expired.',
                ],
            ], 401);
        }

        // Validate IP allowlist if configured
        if (! $apiToken->isValidForIp($request->ip())) {
            return response()->json([
                'error' => [
                    'code' => 'IP_NOT_ALLOWED',
                    'message' => 'Requests from your IP address are not permitted for this API token.',
                ],
            ], 403);
        }

        // Validate scope if required
        if ($scope !== null && ! $apiToken->can($scope)) {
            return response()->json([
                'error' => [
                    'code' => 'INSUFFICIENT_SCOPE',
                    'message' => "This API token does not have the required '{$scope}' scope.",
                ],
            ], 403);
        }

        // Authenticate the user on the request
        $request->setUserResolver(fn () => $apiToken->user);
        $request->attributes->set('api_token', $apiToken);

        // Update token timestamp non-blockingly
        $apiToken->updateQuietly(['last_used_at' => now()]);

        return $next($request);
    }
}
