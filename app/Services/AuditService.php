<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService
{
    /**
     * Keys that must never be recorded in audit logs.
     *
     * @var list<string>
     */
    protected array $redactedKeys = [
        'password',
        'password_confirmation',
        'current_password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'token',
        'api_token',
        'secret',
        'remember_token',
    ];

    /**
     * Record an audit log entry.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        ?Model $target = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $actor = null
    ): AuditLog {
        $actorUser = $actor ?? Auth::user();

        return AuditLog::create([
            'user_id' => $actorUser?->id,
            'action' => $action,
            'target_type' => $target ? get_class($target) : null,
            'target_id' => $target?->getKey(),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'old_values' => $oldValues ? $this->redact($oldValues) : null,
            'new_values' => $newValues ? $this->redact($newValues) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Strip sensitive keys recursively.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        $cleaned = [];

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $this->redactedKeys, true)) {
                $cleaned[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $cleaned[$key] = $this->redact($value);
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }
}
