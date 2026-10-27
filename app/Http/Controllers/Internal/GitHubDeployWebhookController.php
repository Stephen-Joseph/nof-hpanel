<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\DeployRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GitHubDeployWebhookController extends Controller
{
    /**
     * Handle incoming GitHub push webhook for panel auto-deployment.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // 1. Secret & Signature Verification (HMAC-SHA256, hash_equals, no details on failure)
        $secret = (string) (config('hpanel.deploy.webhook_secret') ?: env('DEPLOY_WEBHOOK_SECRET', ''));
        if ($secret === '') {
            abort(403);
        }

        $signatureHeader = (string) $request->header('X-Hub-Signature-256', '');
        if ($signatureHeader === '') {
            abort(403);
        }

        $rawBody = $request->getContent();
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        if (! hash_equals($expectedSignature, $signatureHeader)) {
            abort(403);
        }

        // 2. Event filtering: accept only 'push'
        $event = (string) $request->header('X-GitHub-Event', '');
        if ($event !== 'push') {
            return response()->json([
                'message' => 'Event ignored: ' . ($event !== '' ? $event : 'unknown'),
            ], 202);
        }

        // 3. Branch filtering: accept only 'refs/heads/main'
        $ref = (string) $request->input('ref', '');
        if ($ref !== 'refs/heads/main') {
            return response()->json([
                'message' => 'Branch ignored: ' . ($ref !== '' ? $ref : 'unknown'),
            ], 202);
        }

        // 4. Extract commit payload
        $commitSha = (string) ($request->input('after') ?? $request->input('head_commit.id') ?? '');
        if ($commitSha === '' || $commitSha === '0000000000000000000000000000000000000000') {
            return response()->json([
                'message' => 'No target commit SHA present',
            ], 202);
        }

        $commitMessage = (string) ($request->input('head_commit.message') ?? '');
        $author = (string) (
            $request->input('head_commit.author.name')
            ?? $request->input('head_commit.author.username')
            ?? $request->input('pusher.name')
            ?? 'GitHub'
        );
        $deliveryId = (string) ($request->header('X-GitHub-Delivery') ?? Str::uuid()->toString());

        // Check if any commit modifies or adds database migrations
        $hasMigrations = false;
        $commits = (array) $request->input('commits', []);
        if (empty($commits) && $request->has('head_commit')) {
            $commits = [(array) $request->input('head_commit')];
        }

        foreach ($commits as $c) {
            $files = array_merge(
                (array) ($c['added'] ?? []),
                (array) ($c['modified'] ?? [])
            );
            foreach ($files as $file) {
                if (str_starts_with((string) $file, 'database/migrations/')) {
                    $hasMigrations = true;
                    break 2;
                }
            }
        }

        // 5. Atomic write to trigger file (no commands run from web request)
        $triggerPath = config('hpanel.deploy.trigger_path', '/var/lib/hpanel/deploy/trigger.json');
        $dir = dirname($triggerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $triggerData = [
            'action' => 'deploy',
            'commit_sha' => $commitSha,
            'commit_message' => $commitMessage,
            'author' => $author,
            'delivery_id' => $deliveryId,
            'has_migrations' => $hasMigrations,
            'queued_at' => now()->toIso8601String(),
        ];

        $tempFile = $triggerPath . '.tmp.' . bin2hex(random_bytes(6));
        file_put_contents($tempFile, json_encode($triggerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($tempFile, 0644);
        rename($tempFile, $triggerPath);

        // Also record pending update info for the admin UI
        $pendingPath = config('hpanel.deploy.origin_status_path', '/var/lib/hpanel/deploy/origin_status.json');
        $pendingDir = dirname($pendingPath);
        if (! is_dir($pendingDir)) {
            @mkdir($pendingDir, 0755, true);
        }
        $pendingTemp = $pendingPath . '.tmp.' . bin2hex(random_bytes(6));
        file_put_contents($pendingTemp, json_encode($triggerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($pendingTemp, 0644);
        rename($pendingTemp, $pendingPath);

        // 6. Store a deploy_runs row with status queued
        $deployRun = DeployRun::create([
            'commit_sha' => $commitSha,
            'commit_message' => $commitMessage,
            'author' => $author,
            'delivery_id' => $deliveryId,
            'status' => 'queued',
            'action' => 'deploy',
            'has_migrations' => $hasMigrations,
        ]);

        return response()->json([
            'message' => 'Deployment queued',
            'run_id' => $deployRun->id,
            'commit_sha' => $commitSha,
            'status' => 'queued',
        ], 202);
    }
}

