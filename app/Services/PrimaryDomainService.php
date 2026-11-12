<?php

namespace App\Services;

use App\Enums\AppProfile;
use App\Enums\DomainType;
use App\Enums\WwwRedirect;
use App\Models\Account;
use App\Models\Domain;
use Illuminate\Validation\ValidationException;

class PrimaryDomainService
{
    /**
     * Idempotently create or return the primary domain row for an account.
     *
     * @throws ValidationException
     */
    public function ensureForAccount(Account $account): Domain
    {
        $account->loadMissing('package');

        $domainName = strtolower(trim($account->primary_domain));

        if (! preg_match(Account::DOMAIN_REGEX, $domainName)) {
            throw ValidationException::withMessages([
                'domain' => ["The domain '{$domainName}' is not a valid RFC-compliant domain name."],
            ]);
        }

        /** @var Domain|null $existing */
        $existing = Domain::where('domain', $domainName)->first();

        if ($existing !== null) {
            if ($existing->account_id !== $account->id) {
                throw ValidationException::withMessages([
                    'domain' => ["Domain '{$domainName}' is already registered to another account."],
                ]);
            }

            if (! $existing->is_primary || $existing->type !== DomainType::PRIMARY) {
                $existing->forceFill([
                    'type' => DomainType::PRIMARY,
                    'is_primary' => true,
                ])->save();
            }

            return $existing;
        }

        $phpVersion = '8.3';
        if (! empty($account->package?->allowed_php_versions)) {
            $phpVersion = (string) $account->package->allowed_php_versions[0];
        }

        return Domain::create([
            'account_id' => $account->id,
            'domain' => $domainName,
            'type' => DomainType::PRIMARY,
            'is_primary' => true,
            'document_root' => "/home/{$account->linux_username}/public_html",
            'app_profile' => AppProfile::PHP_GENERIC,
            'php_version' => $phpVersion,
            'verification_token' => bin2hex(random_bytes(16)),
            'verification_status' => 'pending_dns',
            'ssl_status' => 'none',
            'is_apex' => Domain::isApexDomain($domainName),
            'force_https' => false,
            'www_redirect' => WwwRedirect::NONE,
            'is_active' => true,
        ]);
    }
}
