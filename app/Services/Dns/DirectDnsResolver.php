<?php

namespace App\Services\Dns;

class DirectDnsResolver implements DnsResolverInterface
{
    /** @var list<string> */
    protected array $resolvers;

    protected int $timeout;

    /**
     * @param list<string>|null $resolvers
     */
    public function __construct(?array $resolvers = null, int $timeout = 3)
    {
        $this->resolvers = $resolvers ?? ['1.1.1.1', '8.8.8.8'];
        $this->timeout = $timeout;
    }

    /**
     * Fetch DNS records for a given hostname directly from 1.1.1.1 and 8.8.8.8.
     * Records count as seen if either resolver returns them.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecords(string $hostname, int $type = DNS_ANY): array
    {
        $hostname = strtolower(trim($hostname, '.'));
        $rfcType = $this->mapPhpTypeToRfc($type);

        $allRecords = [];

        foreach ($this->resolvers as $resolver) {
            $records = $this->queryResolver($resolver, $hostname, $rfcType);
            foreach ($records as $record) {
                $allRecords[] = $record;
            }
        }

        return $this->deduplicateRecords($allRecords);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function queryResolver(string $resolverIp, string $hostname, int $qtype): array
    {
        $queryPacket = $this->buildQueryPacket($hostname, $qtype);

        // 1. Try UDP first
        $response = $this->sendUdpQuery($resolverIp, $queryPacket);

        // If UDP failed or truncated (TC bit), fallback to TCP
        if ($response === null || (strlen($response) >= 3 && (ord($response[2]) & 0x02) !== 0)) {
            $response = $this->sendTcpQuery($resolverIp, $queryPacket);
        }

        if ($response === null || strlen($response) < 12) {
            return [];
        }

        return $this->parseResponse($response, $hostname);
    }

    protected function sendUdpQuery(string $resolverIp, string $packet): ?string
    {
        $socket = @fsockopen("udp://{$resolverIp}", 53, $errno, $errstr, $this->timeout);
        if (! $socket) {
            return null;
        }

        stream_set_timeout($socket, $this->timeout);
        @fwrite($socket, $packet);
        $response = @fread($socket, 4096);
        @fclose($socket);

        return is_string($response) && strlen($response) > 0 ? $response : null;
    }

    protected function sendTcpQuery(string $resolverIp, string $packet): ?string
    {
        $socket = @fsockopen("tcp://{$resolverIp}", 53, $errno, $errstr, $this->timeout);
        if (! $socket) {
            return null;
        }

        stream_set_timeout($socket, $this->timeout);
        $lengthPrefix = pack('n', strlen($packet));
        @fwrite($socket, $lengthPrefix . $packet);

        $lenData = @fread($socket, 2);
        if (! is_string($lenData) || strlen($lenData) < 2) {
            @fclose($socket);
            return null;
        }

        $length = unpack('n', $lenData)[1] ?? 0;
        if ($length <= 0) {
            @fclose($socket);
            return null;
        }

        $response = '';
        $remaining = $length;
        while ($remaining > 0 && ! feof($socket)) {
            $chunk = @fread($socket, $remaining);
            if (! is_string($chunk) || strlen($chunk) === 0) {
                break;
            }
            $response .= $chunk;
            $remaining -= strlen($chunk);
        }

        @fclose($socket);

        return strlen($response) >= 12 ? $response : null;
    }

    protected function buildQueryPacket(string $hostname, int $qtype): string
    {
        $id = random_bytes(2);
        $flags = "\x01\x00"; // standard query, recursion desired
        $qdcount = "\x00\x01"; // 1 question
        $ancount = "\x00\x00";
        $nscount = "\x00\x00";
        $arcount = "\x00\x00";

        $qname = '';
        $labels = explode('.', $hostname);
        foreach ($labels as $label) {
            if ($label === '') {
                continue;
            }
            $qname .= chr(strlen($label)) . $label;
        }
        $qname .= "\x00";

        $qtypeBytes = pack('n', $qtype);
        $qclassBytes = pack('n', 1); // IN

        return $id . $flags . $qdcount . $ancount . $nscount . $arcount . $qname . $qtypeBytes . $qclassBytes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function parseResponse(string $response, string $queryHost): array
    {
        $records = [];
        $header = substr($response, 0, 12);
        if (strlen($header) < 12) {
            return [];
        }

        $qdcount = unpack('n', substr($header, 4, 2))[1] ?? 0;
        $ancount = unpack('n', substr($header, 6, 2))[1] ?? 0;

        $offset = 12;

        // Skip Question Section
        for ($i = 0; $i < $qdcount; $i++) {
            $this->readName($response, $offset);
            $offset += 4; // QTYPE + QCLASS
            if ($offset >= strlen($response)) {
                return [];
            }
        }

        // Parse Answer Section
        for ($i = 0; $i < $ancount; $i++) {
            if ($offset >= strlen($response)) {
                break;
            }

            $name = $this->readName($response, $offset);
            if ($offset + 10 > strlen($response)) {
                break;
            }

            $type = unpack('n', substr($response, $offset, 2))[1] ?? 0;
            $class = unpack('n', substr($response, $offset + 2, 2))[1] ?? 0;
            $ttl = unpack('N', substr($response, $offset + 4, 4))[1] ?? 0;
            $rdlength = unpack('n', substr($response, $offset + 8, 2))[1] ?? 0;
            $offset += 10;

            if ($offset + $rdlength > strlen($response)) {
                break;
            }

            $rdata = substr($response, $offset, $rdlength);
            $recordOffset = $offset;
            $offset += $rdlength;

            switch ($type) {
                case 1: // A
                    if ($rdlength === 4) {
                        $ip = inet_ntop($rdata);
                        if ($ip !== false) {
                            $records[] = [
                                'host' => $name,
                                'type' => 'A',
                                'ip' => $ip,
                                'ttl' => $ttl,
                                'class' => 'IN',
                            ];
                        }
                    }
                    break;

                case 28: // AAAA
                    if ($rdlength === 16) {
                        $ip = inet_ntop($rdata);
                        if ($ip !== false) {
                            $records[] = [
                                'host' => $name,
                                'type' => 'AAAA',
                                'ipv6' => $ip,
                                'ttl' => $ttl,
                                'class' => 'IN',
                            ];
                        }
                    }
                    break;

                case 5: // CNAME
                    $cnameTarget = $this->readName($response, $recordOffset);
                    $records[] = [
                        'host' => $name,
                        'type' => 'CNAME',
                        'target' => $cnameTarget,
                        'ttl' => $ttl,
                        'class' => 'IN',
                    ];
                    break;

                case 16: // TXT
                    $entries = [];
                    $txtOffset = 0;
                    while ($txtOffset < $rdlength) {
                        $strLen = ord($rdata[$txtOffset]);
                        $txtOffset++;
                        $entry = substr($rdata, $txtOffset, $strLen);
                        $entries[] = $entry;
                        $txtOffset += $strLen;
                    }
                    $joinedTxt = implode('', $entries);
                    $records[] = [
                        'host' => $name,
                        'type' => 'TXT',
                        'txt' => $joinedTxt,
                        'entries' => $entries,
                        'ttl' => $ttl,
                        'class' => 'IN',
                    ];
                    break;
            }
        }

        return $records;
    }

    protected function readName(string $response, int &$offset): string
    {
        $labels = [];
        $jumped = false;
        $originalOffset = $offset;
        $respLen = strlen($response);

        while ($offset < $respLen) {
            $len = ord($response[$offset]);
            if ($len === 0) {
                $offset++;
                break;
            }

            // Compression pointer: high two bits are 11 (0xC0)
            if (($len & 0xC0) === 0xC0) {
                if ($offset + 1 >= $respLen) {
                    break;
                }
                $pointer = (($len & 0x3F) << 8) | ord($response[$offset + 1]);
                if (! $jumped) {
                    $originalOffset = $offset + 2;
                    $jumped = true;
                }
                $offset = $pointer;
                continue;
            }

            $offset++;
            if ($offset + $len > $respLen) {
                break;
            }
            $labels[] = substr($response, $offset, $len);
            $offset += $len;
        }

        if ($jumped) {
            $offset = $originalOffset;
        }

        return implode('.', $labels);
    }

    protected function mapPhpTypeToRfc(int $phpType): int
    {
        return match ($phpType) {
            DNS_A, 1 => 1,
            DNS_CNAME, 5 => 5,
            DNS_TXT, 16 => 16,
            DNS_AAAA, 28 => 28,
            default => 255, // ANY
        };
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    protected function deduplicateRecords(array $records): array
    {
        $unique = [];
        $result = [];

        foreach ($records as $r) {
            $key = ($r['type'] ?? '') . ':' . ($r['host'] ?? '') . ':' . ($r['ip'] ?? '') . ($r['ipv6'] ?? '') . ($r['target'] ?? '') . ($r['txt'] ?? '');
            if (! isset($unique[$key])) {
                $unique[$key] = true;
                $result[] = $r;
            }
        }

        return $result;
    }
}
