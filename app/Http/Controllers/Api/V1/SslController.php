<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IssueSslRequest;
use App\Jobs\IssueSslCertificateJob;
use App\Models\Account;
use App\Models\Domain;
use App\Models\User;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;

class SslController extends Controller
{
    public function issue(IssueSslRequest $request, WebhookService $webhooks): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $domainName = strtolower($request->validated()['domain']);
        $accountId = $request->input('account_id');

        $domainModel = Domain::with(['account.server', 'account.user'])->where('domain', $domainName)->first();

        if ($domainModel) {
            if (! $actor->can('update', $domainModel)) {
                return response()->json([
                    'error' => [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to issue SSL for this domain.',
                    ],
                ], 403);
            }

            $email = $actor->email ?? 'admin@'.config('app.domain', 'hpanel.local');
            IssueSslCertificateJob::dispatch($domainModel, $email);
        } elseif ($accountId) {
            $account = Account::findOrFail($accountId);
            if (! $actor->can('view', $account)) {
                return response()->json([
                    'error' => [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to issue SSL for this account.',
                    ],
                ], 403);
            }
        }

        $webhooks->dispatch('ssl.issued', [
            'domain' => $domainName,
            'status' => 'requested',
            'issued_at' => now()->toIso8601String(),
        ], $actor);

        return response()->json([
            'status' => 'issuing',
            'domain' => $domainName,
            'message' => "SSL issuance process has been initiated for {$domainName}.",
        ], 202);
    }
}
