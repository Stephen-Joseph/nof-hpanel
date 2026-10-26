<?php

namespace App\Services\Dns;

interface DnsResolverInterface
{
    /**
     * Fetch DNS records for a given hostname and DNS record type (DNS_A, DNS_AAAA, DNS_CNAME, DNS_TXT, etc.).
     *
     * @return list<array<string, mixed>>
     */
    public function getRecords(string $hostname, int $type = DNS_ANY): array;
}

