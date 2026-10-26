<?php

namespace App\Services\Dns;

class SystemDnsResolver implements DnsResolverInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getRecords(string $hostname, int $type = DNS_ANY): array
    {
        $records = @dns_get_record($hostname, $type);

        return is_array($records) ? array_values($records) : [];
    }
}

