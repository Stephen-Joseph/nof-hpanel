<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Http\Requests\Api\CreateAccountRequest;
use App\Http\Requests\Api\SuspendAccountRequest;
use App\Http\Requests\Api\UpgradeAccountRequest;
use App\Jobs\ProvisionAccount;
use App\Jobs\SuspendAccount;
use App\Jobs\TerminateAccount;
use App\Jobs\UnsuspendAccount;
use App\Jobs\UpgradeAccount;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    public function store(CreateAccountRequest $request, WebhookService $webhooks): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $actor->can('create', Account::class)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to create accounts.',
                ],
            ], 403);
        }

        $validated = $request->validated();

        // 1. Resolve or create customer user
        /** @var User|null $customer */
        $customer = User::where('email', $validated['customer_email'])->first();
        if (! $customer) {
            $customer = User::create([
                'name' => $validated['customer_name'] ?? $validated['username'],
                'email' => $validated['customer_email'],
                'password' => Hash::make(Str::random(32)),
                'role' => UserRole::CUSTOMER,
                'parent_id' => $actor->isReseller() ? $actor->id : null,
                'email_verified_at' => now(),
            ]);
        } else {
            // Check tenancy
            if ($actor->isReseller() && ! $customer->isDescendantOf($actor) && $customer->id !== $actor->id) {
                return response()->json([
                    'error' => [
                        'code' => 'TENANCY_VIOLATION',
                        'message' => 'Cannot assign account to a customer outside your reseller subtree.',
                    ],
                ], 403);
            }
        }

        // 2. Select package and check tenancy
        $package = Package::availableFor($actor)->findOrFail($validated['package_id']);

        // 3. Select server
        $serverId = $validated['server_id'] ?? null;
        if (! $serverId) {
            $serverId = Server::where('status', 'active')->orderBy('id')->value('id');
            if (! $serverId) {
                return response()->json([
                    'error' => [
                        'code' => 'NO_ACTIVE_SERVER',
                        'message' => 'No active server node is available to provision this account.',
                    ],
                ], 503);
            }
        }

        // 4. Create account in database with pending status
        $account = Account::create([
            'user_id' => $customer->id,
            'package_id' => $package->id,
            'server_id' => $serverId,
            'linux_username' => $validated['username'],
            'primary_domain' => $validated['domain'],
            'status' => AccountStatus::PENDING,
        ]);

        // 5. Enqueue asynchronous provisioning
        ProvisionAccount::dispatch($account);

        // 6. Fire webhook
        $webhooks->dispatch('account.created', [
            'account_id' => $account->id,
            'username' => $account->linux_username,
            'domain' => $account->primary_domain,
            'status' => $account->status->value,
            'package_id' => $package->id,
            'server_id' => $account->server_id,
        ], $actor);

        return response()->json([
            'status' => 'provisioning',
            'account_id' => $account->id,
            'username' => $account->linux_username,
            'domain' => $account->primary_domain,
            'message' => 'Account has been enqueued for asynchronous provisioning.',
        ], 202);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var Account $account */
        $account = Account::with(['package', 'server', 'user'])->findOrFail($id);

        if (! $actor->can('view', $account)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to view this account.',
                ],
            ], 403);
        }

        return response()->json([
            'id' => $account->id,
            'username' => $account->linux_username,
            'primary_domain' => $account->primary_domain,
            'status' => $account->status->value,
            'suspended_reason' => $account->suspended_reason,
            'owner' => [
                'id' => $account->user->id,
                'name' => $account->user->name,
                'email' => $account->user->email,
            ],
            'server' => [
                'id' => $account->server->id,
                'name' => $account->server->name,
                'hostname' => $account->server->hostname,
            ],
            'package' => [
                'id' => $account->package->id,
                'name' => $account->package->name,
                'storage_mb' => $account->package->storage_mb,
                'inodes' => $account->package->inodes,
                'bandwidth_gb' => $account->package->bandwidth_gb,
                'php_workers' => $account->package->php_workers,
                'php_memory_mb' => $account->package->php_memory_mb,
            ],
            'created_at' => $account->created_at?->toIso8601String(),
        ]);
    }

    public function suspend(SuspendAccountRequest $request, int $id, WebhookService $webhooks): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var Account $account */
        $account = Account::findOrFail($id);

        if (! $actor->can('update', $account)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to suspend this account.',
                ],
            ], 403);
        }

        $reason = $request->input('reason', 'Administrative suspension');
        SuspendAccount::dispatch($account, $reason);

        $webhooks->dispatch('account.suspended', [
            'account_id' => $account->id,
            'username' => $account->linux_username,
            'reason' => $reason,
        ], $actor);

        return response()->json([
            'status' => 'suspending',
            'account_id' => $account->id,
            'message' => 'Account suspension has been queued.',
        ]);
    }

    public function unsuspend(Request $request, int $id, WebhookService $webhooks): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var Account $account */
        $account = Account::findOrFail($id);

        if (! $actor->can('update', $account)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to unsuspend this account.',
                ],
            ], 403);
        }

        UnsuspendAccount::dispatch($account);

        $webhooks->dispatch('account.unsuspended', [
            'account_id' => $account->id,
            'username' => $account->linux_username,
        ], $actor);

        return response()->json([
            'status' => 'unsuspending',
            'account_id' => $account->id,
            'message' => 'Account unsuspension has been queued.',
        ]);
    }

    public function upgrade(UpgradeAccountRequest $request, int $id, WebhookService $webhooks): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var Account $account */
        $account = Account::findOrFail($id);

        if (! $actor->can('update', $account)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to upgrade this account.',
                ],
            ], 403);
        }

        $newPackage = Package::availableFor($actor)->findOrFail($request->input('package_id'));
        UpgradeAccount::dispatch($account, $newPackage);

        $webhooks->dispatch('account.upgraded', [
            'account_id' => $account->id,
            'username' => $account->linux_username,
            'new_package_id' => $newPackage->id,
        ], $actor);

        return response()->json([
            'status' => 'upgrading',
            'account_id' => $account->id,
            'new_package_id' => $newPackage->id,
            'message' => 'Account upgrade has been queued.',
        ]);
    }

    public function destroy(Request $request, int $id, WebhookService $webhooks): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var Account $account */
        $account = Account::findOrFail($id);

        if (! $actor->can('delete', $account)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to terminate this account.',
                ],
            ], 403);
        }

        TerminateAccount::dispatch($account, true);

        $webhooks->dispatch('account.terminated', [
            'account_id' => $account->id,
            'username' => $account->linux_username,
        ], $actor);

        return response()->json([
            'status' => 'terminating',
            'account_id' => $account->id,
            'message' => 'Account termination has been enqueued.',
        ], 202);
    }

    public function usage(Request $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var Account $account */
        $account = Account::with('package')->findOrFail($id);

        if (! $actor->can('view', $account)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to view usage for this account.',
                ],
            ], 403);
        }

        // Return usage vs limits
        return response()->json([
            'account_id' => $account->id,
            'username' => $account->linux_username,
            'usage' => [
                'storage_mb' => [
                    'used' => 124, // Mock current used value
                    'limit' => $account->package->storage_mb,
                    'percentage' => round((124 / max(1, $account->package->storage_mb)) * 100, 1),
                ],
                'inodes' => [
                    'used' => 3120,
                    'limit' => $account->package->inodes,
                    'percentage' => round((3120 / max(1, $account->package->inodes)) * 100, 1),
                ],
                'bandwidth_gb' => [
                    'used' => 4.2,
                    'limit' => $account->package->bandwidth_gb,
                    'percentage' => round((4.2 / max(1, $account->package->bandwidth_gb)) * 100, 1),
                ],
                'websites' => [
                    'used' => 1,
                    'limit' => $account->package->max_websites,
                ],
                'databases' => [
                    'used' => 0,
                    'limit' => $account->package->max_databases,
                ],
            ],
        ]);
    }

    public function password(ChangePasswordRequest $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var Account $account */
        $account = Account::with('user')->findOrFail($id);

        if (! $actor->can('update', $account)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to change passwords for this account.',
                ],
            ], 403);
        }

        $account->user->update([
            'password' => Hash::make($request->validated()['password']),
        ]);

        return response()->json([
            'status' => 'password_updated',
            'account_id' => $account->id,
            'message' => 'Customer user password has been updated successfully.',
        ]);
    }

    public function sso(Request $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var Account $account */
        $account = Account::with('user')->findOrFail($id);

        if (! $actor->can('view', $account)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to generate SSO links for this account.',
                ],
            ], 403);
        }

        // Generate a 15-minute signed one-time login URL
        $ssoUrl = URL::temporarySignedRoute(
            'login',
            now()->addMinutes(15),
            ['user' => $account->user->id, 'sso_token' => Str::random(32)]
        );

        return response()->json([
            'account_id' => $account->id,
            'sso_url' => $ssoUrl,
            'expires_in_seconds' => 900,
        ]);
    }
}
