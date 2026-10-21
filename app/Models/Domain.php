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
 * @property bool $force_https
 * @property WwwRedirect $www_redirect
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read Collection<int, Redirect> $redirects
 * @property-read Certificate|null $certificate
 */
#[Fillable([
    'account_id',
    'domain',
    'type',
    'document_root',
    'app_profile',
    'php_version',
    'force_https',
    'www_redirect',
    'is_active',
])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

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
            'force_https' => 'boolean',
            'is_active' => 'boolean',
        ];
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
}
