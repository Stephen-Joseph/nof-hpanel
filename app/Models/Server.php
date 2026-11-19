<?php

namespace App\Models;

use App\Enums\ServerStatus;
use App\Services\Dns\DnsResolverInterface;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $hostname
 * @property string $private_ip
 * @property string|null $public_ip
 * @property string|null $public_ipv6
 * @property string|null $edge_host
 * @property Carbon|null $edge_dns_checked_at
 * @property bool $edge_dns_ok
 * @property string $agent_url
 * @property string|null $agent_hmac_secret
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
    'public_ipv6',
    'edge_host',
    'edge_dns_checked_at',
    'edge_dns_ok',
    'agent_url',
    'agent_hmac_secret',
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
            'agent_hmac_secret' => 'encrypted',
            'max_accounts' => 'integer',
            'cpu_cores' => 'integer',
            'memory_mb' => 'integer',
            'storage_mb' => 'integer',
            'edge_dns_checked_at' => 'datetime',
            'edge_dns_ok' => 'boolean',
        ];
    }

    /**
     * Auto-generate a default edge host for the given server name.
     */
    public static function generateDefaultEdgeHost(string $serverName): string
    {
        $slug = Str::slug($serverName);
        $base = (string) config('hpanel.edge_base', 'nexorafox.in');
        $candidate = "edge-{$slug}.{$base}";

        if (static::where('edge_host', $candidate)->exists()) {
            $candidate = "edge-{$slug}-".strtolower(Str::random(4)).".{$base}";
        }

        return $candidate;
    }

    /**
     * Check if the given IP address is a valid public IPv4 address (not in private or reserved ranges).
     */
    public static function isPublicIpv4(?string $ip): bool
    {
        if (empty($ip)) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Check if the given IP address is a valid IPv6 address.
     */
    public static function isValidIpv6(?string $ip): bool
    {
        if (empty($ip)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Resolve edge_host through DNS and check if it matches this server's public_ip.
     */
    public function checkEdgeDns(?DnsResolverInterface $resolver = null): bool
    {
        if (empty($this->edge_host)) {
            $this->update([
                'edge_dns_checked_at' => now(),
                'edge_dns_ok' => false,
            ]);

            return false;
        }

        $resolver = $resolver ?? app(DnsResolverInterface::class);
        $records = $resolver->getRecords($this->edge_host, DNS_A);
        $resolvedIps = [];
        foreach ($records as $r) {
            if (! empty($r['ip'])) {
                $resolvedIps[] = strtolower(trim((string) $r['ip']));
            }
        }
        $resolvedIps = array_values(array_unique($resolvedIps));

        $targetIp = strtolower(trim((string) $this->public_ip));
        $isOk = ! empty($targetIp) && in_array($targetIp, $resolvedIps, true);

        $this->update([
            'edge_dns_checked_at' => now(),
            'edge_dns_ok' => $isOk,
        ]);

        return $isOk;
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
