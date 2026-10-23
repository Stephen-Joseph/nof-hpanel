<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property int $domain_id
 * @property string $install_path
 * @property string $site_title
 * @property string $admin_email
 * @property string $admin_user
 * @property string $db_name
 * @property string $db_user
 * @property string $table_prefix
 * @property string $locale
 * @property string|null $version
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read Domain $domain
 */
class WordPressInstall extends Model
{
    /**
     * @var string
     */
    protected $table = 'wordpress_installs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'domain_id',
        'install_path',
        'site_title',
        'admin_email',
        'admin_user',
        'db_name',
        'db_user',
        'table_prefix',
        'locale',
        'version',
        'status',
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
}
