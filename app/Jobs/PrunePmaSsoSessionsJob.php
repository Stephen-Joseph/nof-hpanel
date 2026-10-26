<?php

namespace App\Jobs;

use App\Models\PmaSsoSession;
use App\Services\AgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PrunePmaSsoSessionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function handle(AgentClient $client): void
    {
        $expiredSessions = PmaSsoSession::whereNull('revoked_at')
            ->where('expires_at', '<=', now())
            ->with(['account.server'])
            ->get();

        foreach ($expiredSessions as $session) {
            $account = $session->account;
            $server = $account?->server;

            if ($server) {
                try {
                    $client->execute(
                        server: $server,
                        action: 'db.sso_revoke',
                        payload: [
                            'db_user' => $session->db_user,
                        ]
                    );
                } catch (Throwable $e) {
                    // Continue cleanup even if server is unreachable
                }
            }

            $session->update(['revoked_at' => now()]);
        }
    }
}
