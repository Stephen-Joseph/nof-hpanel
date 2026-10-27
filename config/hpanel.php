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
    | Edge & Domain Verification
    |--------------------------------------------------------------------------
    */
    'panel_domain' => env('PANEL_DOMAIN', 'panel.nexorafox.in'),
    'edge_host' => env('HPANEL_EDGE_HOST', 'edge.nexorafox.in'),
    'edge_fallback_ip' => env('HPANEL_EDGE_FALLBACK_IP', null),
    'verification_txt_prefix' => env('HPANEL_VERIFY_TXT_PREFIX', '_hpanel-verify'),

    /*
    |--------------------------------------------------------------------------
    | phpMyAdmin SSO Configuration
    |--------------------------------------------------------------------------
    */
    'pma_url' => env('HPANEL_PMA_URL', 'https://'.env('PMA_DOMAIN', 'pma.nexorafox.in')),
    'pma_shared_secret' => env('PANEL_PMA_SHARED_SECRET', ''),

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
    | Online SFTP Configuration
    |--------------------------------------------------------------------------
    */
    'online_sftp' => [
        'session_timeout_minutes' => (int) env('HPANEL_ONLINE_SFTP_TIMEOUT_MINUTES', 30),
        'owner_pin' => env('HPANEL_ONLINE_SFTP_PIN', '123456'),
        'rate_limit_per_minute' => (int) env('HPANEL_ONLINE_SFTP_RATE_LIMIT', 120),
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

    /*
    |--------------------------------------------------------------------------
    | Auto-Deploy & Updates Configuration
    |--------------------------------------------------------------------------
    */
    'deploy' => [
        'webhook_secret' => env('DEPLOY_WEBHOOK_SECRET', ''),
        'trigger_path' => env('HPANEL_DEPLOY_TRIGGER_PATH', '/var/lib/hpanel/deploy/trigger.json'),
        'releases_path' => env('HPANEL_DEPLOY_RELEASES_PATH', '/var/lib/hpanel/releases'),
        'current_release_path' => env('HPANEL_DEPLOY_CURRENT_PATH', '/var/lib/hpanel/deploy/current_release.json'),
        'origin_status_path' => env('HPANEL_DEPLOY_ORIGIN_STATUS_PATH', '/var/lib/hpanel/deploy/origin_status.json'),
        'lock_file' => env('HPANEL_DEPLOY_LOCK_FILE', '/var/lock/hpanel-deploy.lock'),
        'log_file' => env('HPANEL_DEPLOY_LOG_FILE', '/var/log/hpanel-deploy.log'),
        'repo_url' => env('HPANEL_REPO_URL', 'https://github.com/Stephen-Joseph/nof-hpanel.git'),
    ],
];

