<?php

namespace App\Services\Dns;

use Pdp\ResolvedDomainName;
use Pdp\Rules;

class DomainZoneResolver
{
    protected ?Rules $rules = null;

    public function __construct()
    {
        $base = dirname(__DIR__, 3);
        $paths = [
            $base . '/resources/data/public_suffix_list.dat',
            $base . '/storage/app/public_suffix_list.dat',
            $base . '/vendor/jeremykendall/php-domain-parser/test_data/public_suffix_list.dat',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                try {
                    $this->rules = Rules::fromPath($path);
                    break;
                } catch (\Throwable) {
                    // continue to next path
                }
            }
        }
    }

    /**
     * Resolve domain into zone and record details.
     *
     * @return array{
     *   domain: string,
     *   zone: string,
     *   is_apex: bool,
     *   subdomain: string,
     *   cname_relative_host: string,
     *   cname_full_name: string,
     *   txt_relative_host: string,
     *   txt_full_name: string,
     * }
     */
    public function resolve(string $domainName): array
    {
        $domainName = strtolower(trim($domainName, '.'));
        $txtPrefix = '_hpanel-verify';
        if (function_exists('config') && app()->has('config')) {
            $txtPrefix = (string) config('hpanel.verification_txt_prefix', '_hpanel-verify');
        }

        $zone = $domainName;
        $subdomain = '';
        $isApex = true;

        if ($this->rules !== null) {
            try {
                /** @var ResolvedDomainName $resolved */
                $resolved = $this->rules->resolve($domainName);
                $registrable = $resolved->registrableDomain()->toString();
                if (! empty($registrable)) {
                    $zone = $registrable;
                    $sub = $resolved->subDomain()->toString();
                    $subdomain = $sub !== null ? $sub : '';
                    $isApex = ($subdomain === '');
                }
            } catch (\Throwable) {
                // Fallback heuristic if resolution throws
                $parts = explode('.', $domainName);
                if (count($parts) > 2) {
                    $zone = implode('.', array_slice($parts, -2));
                    $subdomain = implode('.', array_slice($parts, 0, -2));
                    $isApex = false;
                }
            }
        } else {
            $parts = explode('.', $domainName);
            if (count($parts) > 2) {
                $zone = implode('.', array_slice($parts, -2));
                $subdomain = implode('.', array_slice($parts, 0, -2));
                $isApex = false;
            }
        }

        $cnameRelativeHost = $isApex ? '@' : $subdomain;
        $cnameFullName = $domainName;

        $txtRelativeHost = $isApex ? $txtPrefix : "{$txtPrefix}.{$subdomain}";
        $txtFullName = "{$txtPrefix}.{$domainName}";

        return [
            'domain' => $domainName,
            'zone' => $zone,
            'is_apex' => $isApex,
            'subdomain' => $subdomain,
            'cname_relative_host' => $cnameRelativeHost,
            'cname_full_name' => $cnameFullName,
            'txt_relative_host' => $txtRelativeHost,
            'txt_full_name' => $txtFullName,
        ];
    }

    /**
     * Resolve zone and relative host details.
     *
     * @return array{
     *   zone: string,
     *   relative_host: string,
     *   txt_host: string,
     *   txt_full: string,
     *   cname_host: string,
     *   cname_full: string,
     * }
     */
    public function resolveZoneAndRelativeHost(string $domainName): array
    {
        $info = $this->resolve($domainName);

        return [
            'zone' => $info['zone'],
            'relative_host' => $info['cname_relative_host'],
            'txt_host' => $info['txt_relative_host'],
            'txt_full' => $info['txt_full_name'],
            'cname_host' => $info['cname_relative_host'],
            'cname_full' => $info['cname_full_name'],
        ];
    }
}
