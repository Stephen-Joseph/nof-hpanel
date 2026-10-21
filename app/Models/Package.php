<?php

namespace App\Models;

use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int $storage_mb
 * @property int $inodes
 * @property int $bandwidth_gb
 * @property int $max_websites
 * @property int $max_subdomains
 * @property int $max_databases
 * @property int $max_db_users
 * @property int $max_ftp_accounts
 * @property int $max_cron_jobs
 * @property int $php_workers
 * @property int $php_memory_mb
 * @property list<string> $allowed_php_versions
 * @property int $backups_retained
 * @property bool $ssh_enabled
 * @property bool $git_enabled
 * @property int|null $reseller_owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $resellerOwner
 * @property-read Collection<int, Account> $accounts
 */
#[Fillable([
    'name',
    'storage_mb',
    'inodes',
    'bandwidth_gb',
    'max_websites',
    'max_subdomains',
    'max_databases',
    'max_db_users',
    'max_ftp_accounts',
    'max_cron_jobs',
    'php_workers',
    'php_memory_mb',
    'allowed_php_versions',
    'backups_retained',
    'ssh_enabled',
    'git_enabled',
    'reseller_owner_id',
])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'storage_mb' => 'integer',
            'inodes' => 'integer',
            'bandwidth_gb' => 'integer',
            'max_websites' => 'integer',
            'max_subdomains' => 'integer',
            'max_databases' => 'integer',
            'max_db_users' => 'integer',
            'max_ftp_accounts' => 'integer',
            'max_cron_jobs' => 'integer',
            'php_workers' => 'integer',
            'php_memory_mb' => 'integer',
            'allowed_php_versions' => 'array',
            'backups_retained' => 'integer',
            'ssh_enabled' => 'boolean',
            'git_enabled' => 'boolean',
            'reseller_owner_id' => 'integer',
        ];
    }

    /**
     * Reseller who created this package (null for global admin packages).
     *
     * @return BelongsTo<User, $this>
     */
    public function resellerOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_owner_id');
    }

    /**
     * Hosting accounts provisioned with this package.
     *
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Scope packages available for an actor to assign or view.
     *
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    public function scopeAvailableFor(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isReseller()) {
            return $query->where(function (Builder $sub) use ($user): void {
                $sub->whereNull('reseller_owner_id')
                    ->orWhere('reseller_owner_id', $user->id);
            });
        }

        // Customer: packages owned by customer's reseller (if any) or system packages
        if ($user->parent_id) {
            return $query->where(function (Builder $sub) use ($user): void {
                $sub->whereNull('reseller_owner_id')
                    ->orWhere('reseller_owner_id', $user->parent_id);
            });
        }

        return $query->whereNull('reseller_owner_id');
    }
}
