<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $git_deployment_id
 * @property string $release_tag
 * @property string|null $commit_hash
 * @property string|null $commit_message
 * @property string|null $committer
 * @property string $status
 * @property string|null $log_output
 * @property float $duration_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GitDeployment $deployment
 */
class GitRelease extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'git_deployment_id',
        'release_tag',
        'commit_hash',
        'commit_message',
        'committer',
        'status',
        'log_output',
        'duration_seconds',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'duration_seconds' => 'float',
    ];

    /**
     * @return BelongsTo<GitDeployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(GitDeployment::class, 'git_deployment_id');
    }
}
