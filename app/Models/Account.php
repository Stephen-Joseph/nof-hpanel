<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $package_id
 * @property int $server_id
 * @property string $linux_username
 * @property string $primary_domain
 * @property AccountStatus $status
 * @property string|null $suspended_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Package $package
 * @property-read Server $server
 */
#[Fillable([
    'user_id',
    'package_id',
    'server_id',
    'linux_username',
    'primary_domain',
    'status',
    'suspended_reason',
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
            $attributes['suspended_reason'] = $reason;
        } elseif ($newStatus === AccountStatus::ACTIVE) {
            $attributes['suspended_reason'] = null;
        }

        $this->update($attributes);
    }
}
