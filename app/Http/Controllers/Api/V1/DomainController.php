<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AddDomainRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DomainController extends Controller
{
    public function store(AddDomainRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validated();
        /** @var Account $account */
        $account = Account::with('package')->findOrFail($validated['account_id']);

        if (! $actor->can('update', $account)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to manage domains for this account.',
                ],
            ], 403);
        }

        $domain = strtolower($validated['domain']);
        $documentRoot = $validated['document_root'] ?? "/home/{$account->linux_username}/public_html";

        return response()->json([
            'status' => 'domain_added',
            'account_id' => $account->id,
            'domain' => $domain,
            'document_root' => $documentRoot,
            'message' => "Domain '{$domain}' has been registered to account.",
        ], 201);
    }
}
