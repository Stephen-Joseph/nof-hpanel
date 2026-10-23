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
 * @property string $name
 * @property string $charset
 * @property string $collation
 * @property int $size_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read Collection<int, DatabaseUser> $databaseUsers
 * @property-read Collection<int, DatabaseUserGrant> $grants
 */
class Database extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'name',
        'charset',
        'collation',
        'size_bytes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * Account owning this database.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Users granted access to this database.
     *
     * @return BelongsToMany<DatabaseUser, $this>
     */
    public function databaseUsers(): BelongsToMany
    {
        return $this->belongsToMany(DatabaseUser::class, 'database_user_grants')
            ->withPivot('id', 'privileges')
            ->withTimestamps();
    }

    /**
     * Grant records for this database.
     *
     * @return HasMany<DatabaseUserGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(DatabaseUserGrant::class);
    }

    /**
     * Format size in human readable MB or KB.
     */
    public function formattedSize(): string
    {
        if ($this->size_bytes < 1024) {
            return $this->size_bytes.' B';
        }

        if ($this->size_bytes < 1024 * 1024) {
            return round($this->size_bytes / 1024, 2).' KB';
        }

        return round($this->size_bytes / (1024 * 1024), 2).' MB';
    }
}
