<?php

namespace App\Services;

use App\Models\Domain;
use App\Services\Dns\DnsResolverInterface;

class DomainVerificationService
{
    public function __construct(
        protected DnsResolverInterface $resolver
    ) {}

    /**
     * Check DNS records and update domain status.
     *
     * @param Domain $domain
     * @param bool $forceAdminAction When true, explicit admin action will downgrade immediately on failure.
     * @return DomainVerificationResult
     */
    public function verify(Domain $domain, bool $forceAdminAction = false): DomainVerificationResult
    {
        $edgeHost = strtolower(rtrim((string) config('hpanel.edge_host', 'edge.nexorafox.in'), '.'));
        $txtPrefix = (string) config('hpanel.verification_txt_prefix', '_hpanel-verify');

        // 1. Ownership check via TXT record: _hpanel-verify.<domain> must contain "hpanel-verify=<token>" exactly
        $txtHost = "{$txtPrefix}.{$domain->domain}";
        $expectedTokenStr = "hpanel-verify={$domain->verification_token}";

        $txtRecords = $this->resolver->getRecords($txtHost, DNS_TXT);
        $ownershipOk = false;
        $foundTxtValues = [];

        foreach ($txtRecords as $record) {
            $txtValue = $record['txt'] ?? null;
            if ($txtValue !== null) {
                $foundTxtValues[] = $txtValue;
                if ($txtValue === $expectedTokenStr || str_contains($txtValue, $expectedTokenStr)) {
                    $ownershipOk = true;
                    break;
                }
            }

            if (! empty($record['entries']) && is_array($record['entries'])) {
                foreach ($record['entries'] as $entry) {
                    $foundTxtValues[] = $entry;
                    if ($entry === $expectedTokenStr || str_contains($entry, $expectedTokenStr)) {
                        $ownershipOk = true;
                        break 2;
                    }
                }
            }
        }

        // 2. Routing check:
        // For non-apex: CNAME of domain or www subdomain must resolve to edge_host.
        // For apex: A/AAAA records of domain must match resolved IPs of edge_host.
        $routingOk = false;
        $routingDetails = [];

        $cnameRecords = $this->resolver->getRecords($domain->domain, DNS_CNAME);
        $wwwCnameRecords = $this->resolver->getRecords("www.{$domain->domain}", DNS_CNAME);
        $allCnames = array_merge($cnameRecords, $wwwCnameRecords);

        foreach ($allCnames as $rec) {
            if (! empty($rec['target'])) {
                $normTarget = strtolower(rtrim((string) $rec['target'], '.'));
                $routingDetails['cnames'][] = $normTarget;
                if ($normTarget === $edgeHost) {
                    $routingOk = true;
                    break;
                }
            }
        }

        // If apex or if CNAME didn't match, check A/AAAA against resolved edge_host IPs
        if (! $routingOk && ($domain->is_apex || empty($allCnames))) {
            $edgeARecords = $this->resolver->getRecords($edgeHost, DNS_A);
            $edgeAaaaRecords = $this->resolver->getRecords($edgeHost, DNS_AAAA);

            $edgeIps = [];
            foreach ($edgeARecords as $r) {
                if (! empty($r['ip'])) {
                    $edgeIps[] = strtolower(trim((string) $r['ip']));
                }
            }
            foreach ($edgeAaaaRecords as $r) {
                if (! empty($r['ipv6'])) {
                    $edgeIps[] = strtolower(trim((string) $r['ipv6']));
                }
            }
            $edgeIps = array_values(array_unique($edgeIps));
            $routingDetails['edge_ips'] = $edgeIps;

            if (! empty($edgeIps)) {
                $domainARecords = $this->resolver->getRecords($domain->domain, DNS_A);
                $domainAaaaRecords = $this->resolver->getRecords($domain->domain, DNS_AAAA);

                $domainIps = [];
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
                $routingDetails['domain_ips'] = $domainIps;

                // Match if any domain IP matches an edge IP
                if (count(array_intersect($domainIps, $edgeIps)) > 0) {
                    $routingOk = true;
                }
            }
        }

        $isVerified = $ownershipOk && $routingOk;

        // Build plain language messages and short error (max 500 chars)
        if ($isVerified) {
            $message = 'Domain ownership and routing verified successfully.';
            $error = null;
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

        if ($isVerified) {
            $domain->verification_status = 'verified';
            $domain->verified_at = $domain->verified_at ?? now();
            $domain->dns_last_error = null;
            $domain->dns_consecutive_failures = 0;
        } else {
            $domain->dns_last_error = substr($error ?? $message, 0, 500);

            if ($domain->verification_status === 'verified') {
                $domain->dns_consecutive_failures++;
                // Only downgrade after 3 consecutive failures, or on explicit admin action
                if ($forceAdminAction || $domain->dns_consecutive_failures >= 3) {
                    $domain->verification_status = 'failed';
                }
            } else {
                $domain->dns_consecutive_failures++;
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
            details: $routingDetails
        );
    }
}

