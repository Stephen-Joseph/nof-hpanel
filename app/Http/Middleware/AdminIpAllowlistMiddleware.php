<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class AdminIpAllowlistMiddleware
{
    /**
     * Restrict administrative routes to an allowlist of IPs or CIDR subnets.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<int, string> $allowlist */
        $allowlist = config('hpanel.admin_ip_allowlist', []);

        // If no allowlist is configured, permit request
        if (empty($allowlist)) {
            return $next($request);
        }

        $clientIp = $request->ip();

        if ($clientIp !== null && IpUtils::checkIp($clientIp, $allowlist)) {
            return $next($request);
        }

        // Record security audit trail entry on blocked access
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'admin.ip_blocked',
            'target_type' => null,
            'target_id' => null,
            'old_values' => ['attempted_ip' => $clientIp, 'path' => $request->path()],
            'new_values' => ['allowlist' => $allowlist],
            'ip_address' => $clientIp ?? '0.0.0.0',
        ]);

        abort(Response::HTTP_FORBIDDEN, 'Administrative access restricted by IP address allowlist policy.');
    }
}

