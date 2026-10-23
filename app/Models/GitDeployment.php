<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property int|null $domain_id
 * @property string $name
 * @property string $repository_url
 * @property string $branch
 * @property string $deploy_path
 * @property string $public_key
 * @property string $private_key
 * @property string $webhook_secret
 * @property array<int, string>|null $build_steps
 * @property bool $auto_deploy
 * @property Carbon|null $last_deployed_at
 * @property string|null $last_commit_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read Domain|null $domain
 * @property-read Collection<int, GitRelease> $releases
 */
class GitDeployment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'domain_id',
        'name',
        'repository_url',
        'branch',
        'deploy_path',
        'public_key',
        'private_key',
        'webhook_secret',
        'build_steps',
        'auto_deploy',
        'last_deployed_at',
        'last_commit_hash',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'private_key' => 'encrypted',
        'build_steps' => 'array',
        'auto_deploy' => 'boolean',
        'last_deployed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return HasMany<GitRelease, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(GitRelease::class)->latest();
    }
}
