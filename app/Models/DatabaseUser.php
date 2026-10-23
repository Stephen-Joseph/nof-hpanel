<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property string $username
 * @property string $remote_host
 * @property int $max_connections
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read Collection<int, Database> $databases
 * @property-read Collection<int, DatabaseUserGrant> $grants
 * @property-read Collection<int, DatabaseSsoToken> $ssoTokens
 */
class DatabaseUser extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'username',
        'remote_host',
        'max_connections',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_connections' => 'integer',
        ];
    }

    /**
     * Account owning this database user.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Databases accessible by this user.
     *
     * @return BelongsToMany<Database, $this>
     */
    public function databases(): BelongsToMany
    {
        return $this->belongsToMany(Database::class, 'database_user_grants')
            ->withPivot('id', 'privileges')
            ->withTimestamps();
    }

    /**
     * Grant records for this user.
     *
     * @return HasMany<DatabaseUserGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(DatabaseUserGrant::class);
    }

    /**
     * Single-use phpMyAdmin SSO tokens.
     *
     * @return HasMany<DatabaseSsoToken, $this>
     */
    public function ssoTokens(): HasMany
    {
        return $this->hasMany(DatabaseSsoToken::class);
    }
}
