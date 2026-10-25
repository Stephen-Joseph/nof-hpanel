<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Administrator IP Allowlist
    |--------------------------------------------------------------------------
    |
    | When configured, access to /admin/* and /servers* is strictly restricted
    | to matching client IP addresses or CIDR subnets (e.g. 192.168.1.0/24).
    | Comma-separated string or array. Empty means allow all authenticated admins.
    |
    */
    'admin_ip_allowlist' => array_filter(array_map(
        'trim',
        explode(',', env('ADMIN_IP_ALLOWLIST', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    |
    | Enforce Content-Security-Policy, HSTS, X-Frame-Options, and X-Content-Type.
    |
    */
    'security_headers' => [
        'enable_csp' => (bool) env('HPANEL_ENABLE_CSP', true),
        'enable_hsts' => (bool) env('HPANEL_ENABLE_HSTS', true),
        'hsts_max_age' => (int) env('HPANEL_HSTS_MAX_AGE', 31536000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Defaults
    |--------------------------------------------------------------------------
    */
    'agent_port' => (int) env('HPANEL_AGENT_PORT', 8443),
    'agent_timeout_seconds' => (int) env('HPANEL_AGENT_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | File Manager Configuration
    |--------------------------------------------------------------------------
    */
    'file_manager' => [
        'max_upload_size_mb' => (int) env('HPANEL_FILE_UPLOAD_MAX_MB', 100),
        'allowed_extensions' => [
            'zip', 'tar.gz', 'tgz', 'gz', 'tar',
            'txt', 'md', 'html', 'htm', 'css', 'js', 'json', 'xml', 'svg',
            'php', 'phtml', 'sql', 'env', 'htaccess', 'htpasswd', 'ini', 'conf', 'yaml', 'yml',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'pdf', 'csv', 'tsv',
            'woff', 'woff2', 'ttf', 'eot', 'mp4', 'webm', 'mp3', 'wav',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Policies
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'login' => [
            'max_attempts' => (int) env('HPANEL_LOGIN_MAX_ATTEMPTS', 5),
            'decay_seconds' => (int) env('HPANEL_LOGIN_DECAY_SECONDS', 60),
        ],
        'api_tokens' => [
            'per_minute' => (int) env('HPANEL_API_RATE_LIMIT', 60),
        ],
        'server_enrollment' => [
            'per_minute' => 5,
        ],
    ],
];

