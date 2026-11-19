<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Server;
use App\Services\Dns\DnsResolverInterface;
use Illuminate\Support\Facades\Http;

class DomainVerificationService
{
    public function __construct(
        protected DnsResolverInterface $resolver,
        protected ?AgentClient $agentClient = null
    ) {
        $this->agentClient = $agentClient ?? app(AgentClient::class);
    }

    /**
     * Check DNS records and update domain status.
     *
     * @param  bool  $forceAdminAction  When true, explicit admin action will downgrade immediately on failure.
     */
    public function verify(Domain $domain, bool $forceAdminAction = false): DomainVerificationResult
    {
        $domain->loadMissing(['account.server', 'account.user']);

        $node = $domain->account?->server;
        $edgeHost = strtolower(rtrim((string) ($node?->edge_host ?: config('hpanel.edge_host', 'edge.nexorafox.in')), '.'));
        $txtPrefix = (string) config('hpanel.verification_txt_prefix', '_hpanel-verify');

        // 1. Ownership check via TXT record: _hpanel-verify.<domain> must contain "hpanel-verify=<token>"
        $txtHost = "{$txtPrefix}.{$domain->domain}";
        $expectedTokenStr = "hpanel-verify={$domain->verification_token}";

        $txtRecords = $this->resolver->getRecords($txtHost, DNS_TXT);
        $ownershipOk = false;
        $foundTxtValues = [];

        foreach ($txtRecords as $record) {
            $raw = '';
            if (! empty($record['entries']) && is_array($record['entries'])) {
                $raw = implode('', $record['entries']);
            } elseif (! empty($record['txt'])) {
                $raw = (string) $record['txt'];
            }

            // Strip quotes and trim whitespace; handle joined multi-string TXT
            $cleaned = trim(str_replace(['"', "'"], '', $raw));
            if ($cleaned !== '') {
                $foundTxtValues[] = $cleaned;
            }

            if ($cleaned === $expectedTokenStr || str_contains($cleaned, $expectedTokenStr)) {
                $ownershipOk = true;
                break;
            }
        }

        // Ownership fallback: HTTP proof via agent action domain.http_probe
        $httpProofUsed = false;
        if (! $ownershipOk && ! empty($domain->verification_token) && $domain->account?->server) {
            if ($this->attemptHttpProof($domain)) {
                $ownershipOk = true;
                $httpProofUsed = true;
            }
        }

        // 2. Routing check:
        // Follow CNAME chain (max 5 hops) to edge host
        $routingOk = false;
        $cnameTargets = [];

        $current = $domain->domain;
        $visited = [];
        for ($hop = 0; $hop < 5; $hop++) {
            if (isset($visited[$current])) {
                break;
            }
            $visited[$current] = true;

            $records = $this->resolver->getRecords($current, DNS_CNAME);
            $target = null;
            foreach ($records as $rec) {
                if (! empty($rec['target'])) {
                    $target = strtolower(rtrim((string) $rec['target'], '.'));
                    break;
                }
            }

            if (! $target) {
                break;
            }

            $cnameTargets[] = $target;
            if ($target === $edgeHost) {
                $routingOk = true;
                break;
            }
            $current = $target;
        }

        // Also check www.<domain> CNAME
        if (! $routingOk) {
            $wwwRecords = $this->resolver->getRecords("www.{$domain->domain}", DNS_CNAME);
            foreach ($wwwRecords as $rec) {
                if (! empty($rec['target'])) {
                    $normTarget = strtolower(rtrim((string) $rec['target'], '.'));
                    $cnameTargets[] = $normTarget;
                    if ($normTarget === $edgeHost) {
                        $routingOk = true;
                        break;
                    }
                }
            }
        }

        // If not routing via CNAME chain, check A/AAAA records
        $domainIps = [];
        $validTargetIps = [];

        if (! empty($node?->public_ip)) {
            $validTargetIps[] = strtolower(trim((string) $node->public_ip));
        }
        if (! empty($node?->public_ipv6)) {
            $validTargetIps[] = strtolower(trim((string) $node->public_ipv6));
        }

        // Live resolution of node edge host (no hard-coded IPs)
        $edgeARecords = $this->resolver->getRecords($edgeHost, DNS_A);
        $edgeAaaaRecords = $this->resolver->getRecords($edgeHost, DNS_AAAA);
        foreach ($edgeARecords as $r) {
            if (! empty($r['ip'])) {
                $validTargetIps[] = strtolower(trim((string) $r['ip']));
            }
        }
        foreach ($edgeAaaaRecords as $r) {
            if (! empty($r['ipv6'])) {
                $validTargetIps[] = strtolower(trim((string) $r['ipv6']));
            }
        }
        $validTargetIps = array_values(array_unique($validTargetIps));

        $domainARecords = $this->resolver->getRecords($domain->domain, DNS_A);
        $domainAaaaRecords = $this->resolver->getRecords($domain->domain, DNS_AAAA);
        foreach ($domainARecords as $r) {
            if (! empty($r['ip'])) {
                $domainIps[] = strtolower(trim((string) $r['ip']));
            }
        }
        foreach ($domainAaaaRecords as $r) {
            if (! empty($r['ipv6'])) {
                $domainIps[] = strtolower(trim((string) $r['ipv6']));
            }
        }
        $domainIps = array_values(array_unique($domainIps));

        if (! $routingOk && ! empty($domainIps) && ! empty($validTargetIps)) {
            if (count(array_intersect($domainIps, $validTargetIps)) > 0) {
                $routingOk = true;
            }
        }

        // Cross-node detection: check if domain points to another registered node
        $crossNodeConflict = null;
        if (! $routingOk && $node) {
            $otherServers = Server::where('id', '!=', $node->id)->get();
            foreach ($otherServers as $otherServer) {
                $otherEdge = strtolower(rtrim((string) $otherServer->edge_host, '.'));
                $otherIps = array_values(array_filter([
                    strtolower(trim((string) $otherServer->public_ip)),
                    strtolower(trim((string) $otherServer->public_ipv6)),
                ]));

                $matchesCname = ! empty($otherEdge) && in_array($otherEdge, $cnameTargets, true);
                $matchesIp = ! empty(array_intersect($domainIps, $otherIps));

                if ($matchesCname || $matchesIp) {
                    $crossNodeConflict = $otherServer;
                    break;
                }
            }
        }

        $isVerified = $ownershipOk && $routingOk;

        $checkDetails = [
            'txt_values' => $foundTxtValues,
            'cname_targets' => array_values(array_unique($cnameTargets)),
            'domain_ips' => $domainIps,
            'valid_target_ips' => $validTargetIps,
            'resolvers' => ['1.1.1.1', '8.8.8.8'],
            'http_proof' => $httpProofUsed,
            'checked_at' => now()->toIso8601String(),
        ];

        // Build plain language messages and short error (max 500 chars)
        if ($isVerified) {
            $message = 'Domain ownership and routing verified successfully.';
            $error = null;
        } elseif ($crossNodeConflict) {
            $error = "Domain points to another node: {$crossNodeConflict->name} ({$crossNodeConflict->public_ip}) but belongs to {$node->name} ({$node->public_ip}).";
            $message = "Cross-node routing conflict: domain points to another node: {$crossNodeConflict->name} ({$crossNodeConflict->public_ip}) but belongs to {$node->name} ({$node->public_ip}). Please update DNS records to point to {$edgeHost}.";
        } elseif (! $ownershipOk && ! $routingOk) {
            $message = "DNS records not detected yet. TXT record at {$txtHost} was not found, and routing is not pointing to {$edgeHost}. DNS changes can take up to 24 hours to propagate.";
            $error = "Missing TXT ownership record and routing record pointing to {$edgeHost}.";
        } elseif (! $ownershipOk) {
            $message = "Routing is properly configured, but the ownership TXT record at {$txtHost} is missing or incorrect. DNS changes can take up to 24 hours.";
            $error = "TXT record {$txtHost} with value {$expectedTokenStr} not found.";
        } else {
            if ($domain->is_apex) {
                $message = "Ownership is verified, but root domain routing does not point to {$edgeHost}. Please configure an ALIAS/ANAME record or A/AAAA records matching {$edgeHost}.";
                $error = "Apex A/AAAA records do not match {$edgeHost} IP address.";
            } else {
                $message = "Ownership is verified, but CNAME routing does not point to {$edgeHost}. Please ensure {$domain->domain} or www.{$domain->domain} points to {$edgeHost}.";
                $error = "CNAME record does not resolve to {$edgeHost}.";
            }
        }

        // Store timestamps and errors
        $domain->dns_last_checked_at = now();
        $domain->dns_details = $checkDetails;

        if ($isVerified) {
            $domain->verification_status = 'verified';
            $domain->verified_at = $domain->verified_at ?? now();
            $domain->dns_last_error = null;
            $domain->dns_consecutive_failures = 0;
        } else {
            $domain->dns_last_error = substr($error ?? $message, 0, 500);
            $domain->dns_consecutive_failures++;

            if ($domain->verification_status === 'verified') {
                // A verified domain is downgraded ONLY after 3 consecutive failed checks
                if ($forceAdminAction || $domain->dns_consecutive_failures >= 3) {
                    $domain->verification_status = 'failed';
                }
                // Otherwise remains 'verified'!
            } else {
                if ($forceAdminAction || $domain->dns_consecutive_failures >= 3) {
                    $domain->verification_status = 'failed';
                } else {
                    $domain->verification_status = 'pending_dns';
                }
            }
        }

        $domain->save();

        return new DomainVerificationResult(
            ownershipOk: $ownershipOk,
            routingOk: $routingOk,
            verified: $isVerified,
            message: $message,
            error: $error,
            details: $checkDetails
        );
    }

    /**
     * Attempt HTTP proof by writing token to /var/www/_acme/.well-known/hpanel-verify/<token>
     * and fetching http://<domain>/.well-known/hpanel-verify/<token> over the public internet and via resolve.
     */
    protected function attemptHttpProof(Domain $domain): bool
    {
        try {
            $server = $domain->account?->server;
            if (! $server || empty($server->public_ip)) {
                return false;
            }

            $res = $this->agentClient->execute(
                server: $server,
                action: 'domain.http_probe',
                payload: [
                    'token' => $domain->verification_token,
                    'domain' => $domain->domain,
                ],
                dryRun: false
            );

            if (! ($res['success'] ?? false)) {
                return false;
            }

            $url = "http://{$domain->domain}/.well-known/hpanel-verify/{$domain->verification_token}";

            // Check 1: fetch with --resolve <domain>:80:<node.public_ip>
            $resolveOptions = [
                'curl' => [
                    CURLOPT_RESOLVE => ["{$domain->domain}:80:{$server->public_ip}"],
                ],
            ];

            $resolveResponse = Http::timeout(5)
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'HPanel-Verifier/1.0'])
                ->withOptions($resolveOptions)
                ->get($url);

            if (! $resolveResponse->successful() || trim($resolveResponse->body()) !== trim($domain->verification_token)) {
                return false;
            }

            // Check 2: fetch through public DNS
            $publicResponse = Http::timeout(5)
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'HPanel-Verifier/1.0'])
                ->get($url);

            if ($publicResponse->successful() && trim($publicResponse->body()) === trim($domain->verification_token)) {
                return true;
            }
        } catch (\Throwable) {
            // Ignore transient HTTP probe failure
        }

        return false;
    }
}
