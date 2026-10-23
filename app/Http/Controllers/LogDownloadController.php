<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\AgentClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class LogDownloadController extends Controller
{
    /**
     * Download log file contents as a plain text attachment.
     */
    public function download(Request $request, Account $account, string $logFile, AgentClient $client): Response
    {
        Gate::authorize('view', $account);

        // Security check on log filename
        if (! preg_match('/^[a-zA-Z0-9_\-\.]+\.log(\.[0-9]+)?$/', $logFile)) {
            abort(400, 'Invalid log filename.');
        }

        try {
            $response = $client->execute(
                server: $account->server,
                action: 'log.tail',
                payload: [
                    'username' => $account->linux_username,
                    'log_file' => $logFile,
                    'lines' => 1000,
                ],
                dryRun: false
            );

            /** @var list<string> $lines */
            $lines = $response['data']['lines'] ?? [];
            $content = implode("\n", $lines);

            return response($content, 200, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $logFile),
            ]);
        } catch (\Throwable $e) {
            abort(500, 'Failed to fetch log file: '.$e->getMessage());
        }
    }
}
