<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $domain_id
 * @property CertificateType $type
 * @property CertificateStatus $status
 * @property string|null $cert_path
 * @property string|null $key_path
 * @property string|null $issuer
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property bool $auto_renew
 * @property bool $hsts_enabled
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Domain $domain
 */
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'domain_id',
        'type',
        'status',
        'cert_path',
        'key_path',
        'issuer',
        'valid_from',
        'valid_to',
        'auto_renew',
        'hsts_enabled',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CertificateType::class,
            'status' => CertificateStatus::class,
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'auto_renew' => 'boolean',
            'hsts_enabled' => 'boolean',
        ];
    }

    /**
     * Domain protected by this certificate.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Whether the certificate is currently active and not expired.
     */
    public function isActive(): bool
    {
        return $this->status === CertificateStatus::ACTIVE &&
            ($this->valid_to === null || $this->valid_to->isFuture());
    }

    /**
     * Whether the certificate expires within the given window of days.
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->isActive() &&
            $this->valid_to !== null &&
            $this->valid_to->diffInDays(now()) <= $days;
    }

    /**
     * Number of whole days remaining until certificate expiry.
     */
    public function daysRemaining(): ?int
    {
        if ($this->valid_to === null) {
            return null;
        }

        return (int) now()->diffInDays($this->valid_to, false);
    }
}

