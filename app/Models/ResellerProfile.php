<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $max_accounts
 * @property int $max_storage_mb
 * @property int $max_bandwidth_gb
 * @property string $company_name
 * @property string|null $custom_domain
 * @property string|null $logo_url
 * @property string|null $support_email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class ResellerProfile extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'max_accounts',
        'max_storage_mb',
        'max_bandwidth_gb',
        'company_name',
        'custom_domain',
        'logo_url',
        'support_email',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'max_accounts' => 'integer',
        'max_storage_mb' => 'integer',
        'max_bandwidth_gb' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Calculate total accounts created under this reseller's customers.
     */
    public function usedAccountsCount(): int
    {
        $customerIds = $this->user->children()->pluck('id');

        return Account::whereIn('user_id', $customerIds)->count();
    }

    /**
     * Calculate total storage (MB) currently used across this reseller's customer accounts.
     */
    public function usedStorageMb(): float
    {
        $customerIds = $this->user->children()->pluck('id');

        return (float) Account::whereIn('user_id', $customerIds)->sum('current_storage_mb');
    }

    /**
     * Calculate total bandwidth (GB) currently used across this reseller's customer accounts.
     */
    public function usedBandwidthGb(): float
    {
        $customerIds = $this->user->children()->pluck('id');
        $totalBytes = (int) Account::whereIn('user_id', $customerIds)->sum('current_bandwidth_bytes');

        return round($totalBytes / (1024 * 1024 * 1024), 2);
    }

    /**
     * Determine if this reseller can provision an additional account within their pool limit.
     */
    public function canCreateAccount(): bool
    {
        return $this->usedAccountsCount() < $this->max_accounts;
    }
}
