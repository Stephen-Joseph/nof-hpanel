<?php

namespace App\Models;

use App\Enums\ServerStatus;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $hostname
 * @property string $private_ip
 * @property string|null $public_ip
 * @property string $agent_url
 * @property ServerStatus $status
 * @property string|null $agent_public_cert_fingerprint
 * @property int $max_accounts
 * @property int|null $cpu_cores
 * @property int|null $memory_mb
 * @property int|null $storage_mb
 * @property int|null $server_group_id
 * @property string $location
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Account> $accounts
 * @property-read ServerGroup|null $serverGroup
 */
#[Fillable([
    'name',
    'hostname',
    'private_ip',
    'public_ip',
    'agent_url',
    'status',
    'agent_public_cert_fingerprint',
    'max_accounts',
    'cpu_cores',
    'memory_mb',
    'storage_mb',
    'server_group_id',
    'location',
])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServerStatus::class,
            'max_accounts' => 'integer',
            'cpu_cores' => 'integer',
            'memory_mb' => 'integer',
            'storage_mb' => 'integer',
        ];
    }

    /**
     * Accounts hosted on this server.
     *
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Server group / cluster this server belongs to.
     *
     * @return BelongsTo<ServerGroup, $this>
     */
    public function serverGroup(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class);
    }

    /**
     * Check if this server is eligible to host new accounts.
     */
    public function isAvailableForNewAccounts(): bool
    {
        return $this->status->canAcceptAccounts() && $this->accounts()->count() < $this->max_accounts;
    }
}
