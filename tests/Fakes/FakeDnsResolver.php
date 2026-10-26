<?php

namespace Tests\Fakes;

use App\Services\Dns\DnsResolverInterface;

class FakeDnsResolver implements DnsResolverInterface
{
    /**
     * @var array<string, array<int, list<array<string, mixed>>>>
     */
    protected array $records = [];

    /**
     * @param array<string, mixed> $data
     */
    public function addRecord(string $hostname, int $type, array $data): self
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        $data['type'] = $type;
        $data['host'] = $hostname;
        $this->records[$hostname][$type][] = $data;

        return $this;
    }

    public function setTxt(string $hostname, string $txt): self
    {
        return $this->addRecord($hostname, DNS_TXT, ['txt' => $txt, 'entries' => [$txt]]);
    }

    public function setCname(string $hostname, string $target): self
    {
        return $this->addRecord($hostname, DNS_CNAME, ['target' => $target]);
    }

    public function setA(string $hostname, string $ip): self
    {
        return $this->addRecord($hostname, DNS_A, ['ip' => $ip]);
    }

    public function setAaaa(string $hostname, string $ipv6): self
    {
        return $this->addRecord($hostname, DNS_AAAA, ['ipv6' => $ipv6]);
    }

    public function clear(): self
    {
        $this->records = [];

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecords(string $hostname, int $type = DNS_ANY): array
    {
        $hostname = strtolower(rtrim($hostname, '.'));

        if (! isset($this->records[$hostname])) {
            return [];
        }

        if ($type === DNS_ANY) {
            $all = [];
            foreach ($this->records[$hostname] as $typeRecords) {
                $all = array_merge($all, $typeRecords);
            }

            return $all;
        }

        return $this->records[$hostname][$type] ?? [];
    }
}

