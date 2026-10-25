<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property int $package_id
 * @property int $server_id
 * @property string $linux_username
 * @property string $primary_domain
 * @property AccountStatus $status
 * @property string|null $suspended_reason
 * @property float $current_storage_mb
 * @property int $current_inodes
 * @property int $current_bandwidth_bytes
 * @property Carbon|null $bandwidth_reset_at
 * @property array<string, int>|null $log_offsets
 * @property int $usage_warning_level
 * @property bool $is_throttled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Package $package
 * @property-read Server $server
 * @property-read Collection<int, Database> $databases
 * @property-read Collection<int, DatabaseUser> $databaseUsers
 * @property-read Collection<int, CronJob> $cronJobs
 * @property-read Collection<int, Backup> $backups
 * @property-read BackupSchedule|null $backupSchedule
 * @property-read Collection<int, GitDeployment> $gitDeployments
 * @property-read Collection<int, WordPressInstall> $wordPressInstalls
 * @property-read Collection<int, UsageSnapshot> $usageSnapshots
 */
#[Fillable([
    'user_id',
    'package_id',
    'server_id',
    'linux_username',
    'primary_domain',
    'status',
    'suspended_reason',
    'current_storage_mb',
    'current_inodes',
    'current_bandwidth_bytes',
    'bandwidth_reset_at',
    'log_offsets',
    'usage_warning_level',
    'is_throttled',
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * Regular expression allow-list for Linux usernames:
     * Begins with a lowercase letter, 3 to 16 characters total, lowercase alphanumeric only.
     */
    public const USERNAME_REGEX = '/^[a-z][a-z0-9]{2,15}$/';

    /**
     * Regular expression allow-list for RFC-compliant domain names.
     */
    public const DOMAIN_REGEX = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'package_id' => 'integer',
            'server_id' => 'integer',
            'status' => AccountStatus::class,
            'current_storage_mb' => 'float',
            'current_inodes' => 'integer',
            'current_bandwidth_bytes' => 'integer',
            'bandwidth_reset_at' => 'datetime',
            'log_offsets' => 'array',
            'usage_warning_level' => 'integer',
            'is_throttled' => 'boolean',
        ];
    }

    /**
     * Customer who owns this hosting account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hosting package containing resource limits.
     *
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Target server hosting this account.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * All domains associated with this hosting account.
     *
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * Secondary FTP / SFTP sub-accounts.
     *
     * @return HasMany<FtpAccount, $this>
     */
    public function ftpAccounts(): HasMany
    {
        return $this->hasMany(FtpAccount::class);
    }

    /**
     * Authorized SSH keys for this account.
     *
     * @return HasMany<SshKey, $this>
     */
    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class);
    }

    /**
     * MariaDB databases owned by this account.
     *
     * @return HasMany<Database, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    /**
     * MariaDB database users owned by this account.
     *
     * @return HasMany<DatabaseUser, $this>
     */
    public function databaseUsers(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }

    /**
     * Determine if account has reached its database limit.
     */
    public function canCreateDatabase(): bool
    {
        return $this->databases()->count() < $this->package->max_databases;
    }

    /**
     * Determine if account has reached its database user limit.
     */
    public function canCreateDatabaseUser(): bool
    {
        return $this->databaseUsers()->count() < $this->package->max_db_users;
    }

    /**
     * Cron scheduled jobs for this account.
     *
     * @return HasMany<CronJob, $this>
     */
    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }

    /**
     * Determine if account has reached its scheduled cron jobs limit.
     */
    public function canCreateCronJob(): bool
    {
        return $this->cronJobs()->count() < $this->package->max_cron_jobs;
    }

    /**
     * Restic backup snapshots for this account.
     *
     * @return HasMany<Backup, $this>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class)->latest();
    }

    /**
     * Configured automated backup policy for this account.
     *
     * @return HasOne<BackupSchedule, $this>
     */
    public function backupSchedule(): HasOne
    {
        return $this->hasOne(BackupSchedule::class);
    }

    /**
     * Git deployments for this account.
     *
     * @return HasMany<GitDeployment, $this>
     */
    public function gitDeployments(): HasMany
    {
        return $this->hasMany(GitDeployment::class);
    }

    /**
     * Determine if account package has Git deployment feature enabled.
     */
    public function canUseGit(): bool
    {
        return (bool) $this->package->git_enabled;
    }

    /**
     * WordPress installations for this account.
     *
     * @return HasMany<WordPressInstall, $this>
     */
    public function wordPressInstalls(): HasMany
    {
        return $this->hasMany(WordPressInstall::class);
    }

    /**
     * Periodic resource usage snapshots for this account.
     *
     * @return HasMany<UsageSnapshot, $this>
     */
    public function usageSnapshots(): HasMany
    {
        return $this->hasMany(UsageSnapshot::class)->latest('recorded_at');
    }

    /**
     * Calculate current storage consumption percentage against package quota.
     */
    public function storagePercentage(): float
    {
        $limit = max(1, (int) $this->package->storage_mb);

        return round(($this->current_storage_mb / $limit) * 100, 1);
    }

    /**
     * Calculate current inodes consumption percentage against package limit.
     */
    public function inodesPercentage(): float
    {
        $limit = max(1, (int) $this->package->inodes);

        return round(($this->current_inodes / $limit) * 100, 1);
    }

    /**
     * Calculate monthly bandwidth consumption percentage against package limit.
     */
    public function bandwidthPercentage(): float
    {
        $limitBytes = max(1, (int) $this->package->bandwidth_gb) * 1024 * 1024 * 1024;

        return round(($this->current_bandwidth_bytes / $limitBytes) * 100, 1);
    }

    /**
     * Get the highest usage percentage among storage, inodes, and bandwidth.
     */
    public function highestUsagePercentage(): float
    {
        return max($this->storagePercentage(), $this->inodesPercentage(), $this->bandwidthPercentage());
    }

    /**
     * Determine if a backup is currently actively running for this account.
     */
    public function hasRunningBackup(): bool
    {
        return $this->backups()->whereIn('status', ['pending', 'running'])->exists();
    }

    /**
     * Filter accounts visible to the given user based on tenancy.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isReseller()) {
            $descendantIds = $user->getAllDescendantIds();
            $allowedUserIds = array_merge([$user->id], $descendantIds);

            return $query->whereIn('user_id', $allowedUserIds);
        }

        return $query->where('user_id', $user->id);
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === AccountStatus::PENDING;
    }

    public function isSuspended(): bool
    {
        return $this->status === AccountStatus::SUSPENDED;
    }

    public function isFailed(): bool
    {
        return $this->status === AccountStatus::FAILED;
    }

    /**
     * @return HasMany<AgentJob, $this>
     */
    public function agentJobs(): HasMany
    {
        return $this->hasMany(AgentJob::class)->orderBy('created_at', 'desc');
    }

    public function canTransitionTo(AccountStatus $newStatus): bool
    {
        return match ($this->status) {
            AccountStatus::PENDING => in_array($newStatus, [AccountStatus::ACTIVE, AccountStatus::FAILED], true),
            AccountStatus::FAILED => in_array($newStatus, [AccountStatus::PENDING, AccountStatus::TERMINATING, AccountStatus::TERMINATED], true),
            AccountStatus::ACTIVE => in_array($newStatus, [AccountStatus::SUSPENDED, AccountStatus::TERMINATING], true),
            AccountStatus::SUSPENDED => in_array($newStatus, [AccountStatus::ACTIVE, AccountStatus::TERMINATING], true),
            AccountStatus::TERMINATING => in_array($newStatus, [AccountStatus::TERMINATED, AccountStatus::FAILED], true),
            AccountStatus::TERMINATED => false,
        };
    }

    public function transitionTo(AccountStatus $newStatus, ?string $reason = null): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException("Illegal state transition from {$this->status->value} to {$newStatus->value}");
        }

        $attributes = ['status' => $newStatus];
        if ($reason !== null) {
            $attributes['suspended_reason'] = Str::limit($reason, 500, '');
        } elseif ($newStatus === AccountStatus::ACTIVE) {
            $attributes['suspended_reason'] = null;
        }

        $this->update($attributes);
    }
}
