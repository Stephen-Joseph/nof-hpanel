<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\DatabaseSsoToken;
use App\Models\DatabaseUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DatabaseSsoController extends Controller
{
    /**
     * Generate a single-use SSO token for phpMyAdmin/Adminer and redirect.
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
     * Consume the single-use token and launch database manager session.
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
}
