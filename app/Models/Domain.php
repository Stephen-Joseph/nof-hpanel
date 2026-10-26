<?php

namespace App\Models;

use App\Enums\AppProfile;
use App\Enums\DomainType;
use App\Enums\WwwRedirect;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property string $domain
 * @property DomainType $type
 * @property string $document_root
 * @property AppProfile $app_profile
 * @property string $php_version
 * @property array<string, mixed>|null $php_ini
 * @property bool $force_https
 * @property WwwRedirect $www_redirect
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read Collection<int, Redirect> $redirects
 * @property-read Certificate|null $certificate
 * @property string|null $verification_token
 * @property string $verification_status
 * @property Carbon|null $verified_at
 * @property Carbon|null $dns_last_checked_at
 * @property string|null $dns_last_error
 * @property int $dns_consecutive_failures
 * @property bool $is_apex
 * @property string $ssl_status
 * @property string|null $ssl_last_error
 * @property Carbon|null $ssl_expires_at
 * @property Carbon|null $ssl_requested_at
 * @property-read Collection<int, ProtectedDirectory> $protectedDirectories
 * @property-read Collection<int, GitDeployment> $gitDeployments
 * @property-read Collection<int, WordPressInstall> $wordPressInstalls
 */
#[Fillable([
    'account_id',
    'domain',
    'type',
    'document_root',
    'app_profile',
    'php_version',
    'php_ini',
    'force_https',
    'www_redirect',
    'is_active',
    'verification_token',
    'verification_status',
    'verified_at',
    'dns_last_checked_at',
    'dns_last_error',
    'dns_consecutive_failures',
    'is_apex',
    'ssl_status',
    'ssl_last_error',
    'ssl_expires_at',
    'ssl_requested_at',
])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Domain $domain) {
            if (empty($domain->verification_token)) {
                $domain->verification_token = bin2hex(random_bytes(16));
            }
            if ($domain->is_apex === null) {
                $domain->is_apex = static::isApexDomain($domain->domain);
            }
            if (empty($domain->verification_status)) {
                $domain->verification_status = 'pending_dns';
            }
            if (empty($domain->ssl_status)) {
                $domain->ssl_status = 'none';
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'app_profile' => AppProfile::class,
            'www_redirect' => WwwRedirect::class,
            'php_ini' => 'array',
            'force_https' => 'boolean',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
            'dns_last_checked_at' => 'datetime',
            'dns_consecutive_failures' => 'integer',
            'is_apex' => 'boolean',
            'ssl_expires_at' => 'datetime',
            'ssl_requested_at' => 'datetime',
        ];
    }

    /**
     * Determine if a hostname is an apex (root) domain.
     */
    public static function isApexDomain(string $hostname): bool
    {
        $hostname = strtolower(trim($hostname, '.'));
        $parts = explode('.', $hostname);
        $count = count($parts);
        if ($count <= 2) {
            return true;
        }

        $lastTwo = $parts[$count - 2].'.'.$parts[$count - 1];
        $twoPartSuffixes = [
            'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk',
            'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
            'co.nz', 'net.nz', 'org.nz',
            'co.in', 'net.in', 'org.in', 'gen.in', 'ind.in',
            'co.za', 'org.za',
            'com.br', 'org.br',
            'co.jp', 'ne.jp',
        ];

        if (in_array($lastTwo, $twoPartSuffixes, true)) {
            return $count === 3;
        }

        return false;
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function isSslActive(): bool
    {
        return $this->ssl_status === 'active';
    }

    public function getVerificationTxtHost(): string
    {
        $prefix = config('hpanel.verification_txt_prefix', '_hpanel-verify');

        return "{$prefix}.{$this->domain}";
    }

    public function getVerificationTxtValue(): string
    {
        return "hpanel-verify={$this->verification_token}";
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<Redirect, $this>
     */
    public function redirects(): HasMany
    {
        return $this->hasMany(Redirect::class);
    }

    /**
     * @return HasOne<Certificate, $this>
     */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    /**
     * Determine if this domain currently has an active SSL certificate.
     */
    public function hasActiveCertificate(): bool
    {
        return $this->certificate !== null && $this->certificate->isActive();
    }

    /**
     * @return HasMany<ProtectedDirectory, $this>
     */
    public function protectedDirectories(): HasMany
    {
        return $this->hasMany(ProtectedDirectory::class);
    }

    /**
     * @return HasMany<GitDeployment, $this>
     */
    public function gitDeployments(): HasMany
    {
        return $this->hasMany(GitDeployment::class);
    }

    /**
     * @return HasMany<WordPressInstall, $this>
     */
    public function wordPressInstalls(): HasMany
    {
        return $this->hasMany(WordPressInstall::class);
    }

    /**
     * Get customized or default PHP INI directives.
     *
     * @return array<string, mixed>
     */
    public function getPhpIni(): array
    {
        return array_merge([
            'memory_limit' => '256M',
            'upload_max_filesize' => '32M',
            'post_max_size' => '32M',
            'max_execution_time' => 60,
            'display_errors' => 'off',
        ], $this->php_ini ?? []);
    }
}
