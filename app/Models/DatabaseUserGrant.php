<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $database_id
 * @property int $database_user_id
 * @property array<int, string> $privileges
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Database $database
 * @property-read DatabaseUser $databaseUser
 */
class DatabaseUserGrant extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'database_id',
        'database_user_id',
        'privileges',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'privileges' => 'array',
        ];
    }

    /**
     * Associated database.
     *
     * @return BelongsTo<Database, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /**
     * Associated database user.
     *
     * @return BelongsTo<DatabaseUser, $this>
     */
    public function databaseUser(): BelongsTo
    {
        return $this->belongsTo(DatabaseUser::class);
    }
}
