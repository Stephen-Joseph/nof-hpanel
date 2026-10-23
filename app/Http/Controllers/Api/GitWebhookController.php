<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ExecuteGitDeployJob;
use App\Models\GitDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitWebhookController extends Controller
{
    /**
     * Handle incoming Git deployment webhook from GitHub, GitLab, etc.
     */
    public function __invoke(Request $request, string $secret): JsonResponse
    {
        /** @var GitDeployment|null $deployment */
        $deployment = GitDeployment::with(['account.server', 'domain'])->where('webhook_secret', $secret)->first();

        if (! $deployment) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Deployment webhook target was not found or is invalid.',
                ],
            ], 404);
        }

        if (! $deployment->auto_deploy) {
            return response()->json([
                'status' => 'ignored',
                'message' => 'Auto-deployment is disabled for this repository deployment.',
            ], 200);
        }

        if ($deployment->account->status !== AccountStatus::ACTIVE) {
            return response()->json([
                'error' => [
                    'code' => 'ACCOUNT_SUSPENDED',
                    'message' => 'Associated account is not currently active.',
                ],
            ], 403);
        }

        ExecuteGitDeployJob::dispatch($deployment);

        return response()->json([
            'success' => true,
            'message' => 'Deployment job queued successfully.',
            'deployment' => [
                'id' => $deployment->id,
                'name' => $deployment->name,
                'branch' => $deployment->branch,
                'repository_url' => $deployment->repository_url,
            ],
        ], 202);
    }
}
