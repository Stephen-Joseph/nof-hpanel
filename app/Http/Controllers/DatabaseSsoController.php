<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\DatabaseSsoToken;
use App\Models\DatabaseUser;
use App\Models\PmaSsoSession;
use App\Models\PmaSsoToken;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DatabaseSsoController extends Controller
{
    /**
     * Generate a single-use SSO token for phpMyAdmin/Adminer and redirect (legacy user-bound).
     */
    public function launch(Request $request, Account $account, DatabaseUser $databaseUser): RedirectResponse
    {
        if ($databaseUser->account_id !== $account->id) {
            abort(403, 'Unauthorized database user access.');
        }

        $targetDb = $request->query('db');
        if (is_string($targetDb) && ! empty($targetDb)) {
            // Ensure target db belongs to this account
            if (! $account->databases()->where('name', $targetDb)->exists()) {
                abort(403, 'Unauthorized database target.');
            }
        } else {
            $targetDb = null;
        }

        $ssoToken = DatabaseSsoToken::generate(
            user: $databaseUser,
            targetDb: $targetDb,
            ttlSeconds: 60,
            ipAddress: $request->ip()
        );

        return redirect()->route('pma.sso', ['token' => $ssoToken->token]);
    }

    /**
     * Launch phpMyAdmin SSO session for an account (or specific database).
     */
    public function launchPma(Request $request, Account $account): RedirectResponse
    {
        Gate::authorize('view', $account);

        $targetDb = $request->query('db');
        if (is_string($targetDb) && ! empty($targetDb)) {
            if (! $account->databases()->where('name', $targetDb)->exists()) {
                abort(403, 'Unauthorized database target.');
            }
        } else {
            $targetDb = null;
        }

        // Random 64 hex characters (32 random bytes)
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        PmaSsoToken::create([
            'account_id' => $account->id,
            'user_id' => $request->user()->id,
            'token_hash' => $tokenHash,
            'target_db' => $targetDb,
            'ip_address' => $request->ip(),
            'expires_at' => now()->addSeconds(60),
        ]);

        $pmaUrl = rtrim((string) config('hpanel.pma_url', 'https://pma.nexorafox.in'), '/');

        return redirect()->away($pmaUrl.'/signon.php?token='.$rawToken);
    }

    /**
     * Consume the single-use token and launch database manager session (legacy bridge view).
     */
    public function consume(Request $request, string $token): View
    {
        /** @var DatabaseSsoToken|null $ssoToken */
        $ssoToken = DatabaseSsoToken::with(['databaseUser.account.server'])
            ->where('token', $token)
            ->first();

        if (! $ssoToken || ! $ssoToken->isValid()) {
            abort(403, 'This database SSO token is invalid or has expired.');
        }

        // Mark consumed immediately (single use)
        $ssoToken->markUsed();

        return view('databases.pma-bridge', [
            'ssoToken' => $ssoToken,
            'databaseUser' => $ssoToken->databaseUser,
            'account' => $ssoToken->databaseUser->account,
            'targetDb' => $ssoToken->target_db,
        ]);
    }

    /**
     * Internal endpoint to redeem a signed phpMyAdmin SSO token.
     * Only accepted from 127.0.0.1 with HMAC-SHA256 signature.
     */
    public function redeem(Request $request, AgentClient $agentClient): JsonResponse
    {
        // 1. IP restriction: loopback only
        $clientIp = $request->ip();
        if (! in_array($clientIp, ['127.0.0.1', '::1'], true)) {
            abort(403, 'Forbidden');
        }

        // 2. Signature and timestamp verification
        $timestamp = $request->header('X-Timestamp');
        $signature = $request->header('X-Signature');
        $token = $request->input('token');

        if (! $timestamp || ! $signature || ! $token || ! is_string($token) || ! is_string($timestamp) || ! is_string($signature)) {
            abort(403, 'Forbidden');
        }

        // 30 second maximum timestamp skew
        if (abs(time() - (int) $timestamp) > 30) {
            abort(403, 'Forbidden');
        }

        $secret = (string) config('hpanel.pma_shared_secret');
        if (empty($secret)) {
            abort(403, 'Forbidden');
        }

        $expectedSignature = hash_hmac('sha256', $timestamp.'.'.$token, $secret);
        if (! hash_equals($expectedSignature, $signature)) {
            abort(403, 'Forbidden');
        }

        // 3. Token lookup & single-use verification
        $tokenHash = hash('sha256', $token);
        /** @var PmaSsoToken|null $tokenRecord */
        $tokenRecord = PmaSsoToken::with(['account.server'])->where('token_hash', $tokenHash)->first();

        if (! $tokenRecord || ! $tokenRecord->isValid()) {
            abort(403, 'Forbidden');
        }

        // Mark consumed immediately
        $tokenRecord->markUsed();

        // 4. Provision temporary user on agent
        $account = $tokenRecord->account;
        $server = $account?->server;
        if (! $server) {
            abort(403, 'Forbidden');
        }

        try {
            $response = $agentClient->execute(
                server: $server,
                action: 'db.sso_create',
                payload: [
                    'account_username' => $account->linux_username,
                    'database' => $tokenRecord->target_db,
                ]
            );
        } catch (\Throwable $e) {
            abort(403, 'Forbidden');
        }

        $data = $response['data'] ?? [];
        $dbUser = $data['db_user'] ?? null;
        $dbPass = $data['db_password'] ?? null;

        if (! $dbUser || ! $dbPass) {
            abort(403, 'Forbidden');
        }

        // 5. Record session in database for scheduled pruning
        PmaSsoSession::create([
            'account_id' => $account->id,
            'user_id' => $tokenRecord->user_id,
            'db_user' => $dbUser,
            'target_db' => $tokenRecord->target_db,
            'expires_at' => now()->addMinutes(30),
        ]);

        return response()->json([
            'user' => $dbUser,
            'password' => $dbPass,
            'database' => $tokenRecord->target_db,
        ]);
    }

    /**
     * End phpMyAdmin SSO session and revoke temporary MariaDB credentials.
     */
    public function logout(Request $request, AgentClient $agentClient): RedirectResponse
    {
        if (auth()->check()) {
            $sessions = PmaSsoSession::where('user_id', auth()->id())
                ->whereNull('revoked_at')
                ->with(['account.server'])
                ->get();

            foreach ($sessions as $session) {
                if ($session->account?->server) {
                    try {
                        $agentClient->execute(
                            server: $session->account->server,
                            action: 'db.sso_revoke',
                            payload: ['db_user' => $session->db_user]
                        );
                    } catch (\Throwable $e) {
                        // Suppress agent error during logout
                    }
                }
                $session->update(['revoked_at' => now()]);
            }
        }

        return redirect()->route('accounts.index')->with('status', 'phpMyAdmin session ended.');
    }
}
