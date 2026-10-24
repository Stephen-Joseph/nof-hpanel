<?php

return [
    'title' => 'Hosting Panel REST API Reference',
    'version' => 'v1',
    'base_url' => rtrim(config('app.url', 'http://localhost:8000'), '/').'/api/v1',
    'openapi_url' => '/docs/openapi.yaml',

    'endpoints' => [
        [
            'id' => 'accounts-create',
            'group' => 'Accounts',
            'title' => 'Create Hosting Account',
            'method' => 'POST',
            'path' => '/api/v1/accounts',
            'scope' => 'accounts:write',
            'description' => 'Enqueues an asynchronous provisioning job to create an isolated Linux user account, vhost, and PHP-FPM pool.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
                'Content-Type' => 'application/json',
                'Idempotency-Key' => 'unique-uuid-or-string (optional)',
            ],
            'request_body' => [
                'username' => 'acmeuser',
                'domain' => 'acme.org',
                'package_id' => 1,
                'server_id' => 1,
                'customer_email' => 'client@acme.org',
                'customer_name' => 'Acme Corp',
            ],
            'response' => [
                'status' => 202,
                'body' => [
                    'status' => 'provisioning',
                    'message' => 'Account provisioning job dispatched.',
                    'job_id' => 42,
                    'account_id' => 10,
                ],
            ],
            'samples' => [
                'curl' => "curl -X POST \"https://panel.example.com/api/v1/accounts\" \\\n  -H \"Authorization: Bearer hp_secret_token\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"username\":\"acmeuser\",\"domain\":\"acme.org\",\"package_id\":1,\"customer_email\":\"client@acme.org\"}'",
                'php' => "\$client = new \\GuzzleHttp\\Client();\n\$response = \$client->post('https://panel.example.com/api/v1/accounts', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n    'json' => [\n        'username' => 'acmeuser',\n        'domain' => 'acme.org',\n        'package_id' => 1,\n        'customer_email' => 'client@acme.org',\n    ],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/accounts', {\n  method: 'POST',\n  headers: {\n    'Authorization': 'Bearer hp_secret_token',\n    'Content-Type': 'application/json'\n  },\n  body: JSON.stringify({\n    username: 'acmeuser',\n    domain: 'acme.org',\n    package_id: 1,\n    customer_email: 'client@acme.org'\n  })\n});",
            ],
        ],
        [
            'id' => 'accounts-get',
            'group' => 'Accounts',
            'title' => 'Get Account Details',
            'method' => 'GET',
            'path' => '/api/v1/accounts/{id}',
            'scope' => 'accounts:read',
            'description' => 'Retrieves configuration details, quotas, and current status of a hosting account.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
                'Accept' => 'application/json',
            ],
            'request_body' => null,
            'response' => [
                'status' => 200,
                'body' => [
                    'account' => [
                        'id' => 10,
                        'username' => 'acmeuser',
                        'primary_domain' => 'acme.org',
                        'status' => 'active',
                        'server' => ['id' => 1, 'name' => 'Node-01'],
                        'package' => ['id' => 1, 'name' => 'Business'],
                    ],
                ],
            ],
            'samples' => [
                'curl' => "curl -X GET \"https://panel.example.com/api/v1/accounts/10\" \\\n  -H \"Authorization: Bearer hp_secret_token\"",
                'php' => "\$response = \$client->get('https://panel.example.com/api/v1/accounts/10', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/accounts/10', {\n  headers: { 'Authorization': 'Bearer hp_secret_token' }\n});",
            ],
        ],
        [
            'id' => 'accounts-suspend',
            'group' => 'Accounts',
            'title' => 'Suspend Account',
            'method' => 'POST',
            'path' => '/api/v1/accounts/{id}/suspend',
            'scope' => 'accounts:write',
            'description' => 'Suspends a hosting account, disabling web traffic, PHP-FPM, and SSH/FTP access.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
                'Content-Type' => 'application/json',
            ],
            'request_body' => [
                'reason' => 'Non-payment invoice #9201',
            ],
            'response' => [
                'status' => 200,
                'body' => [
                    'status' => 'suspended',
                    'message' => 'Account suspended successfully.',
                ],
            ],
            'samples' => [
                'curl' => "curl -X POST \"https://panel.example.com/api/v1/accounts/10/suspend\" \\\n  -H \"Authorization: Bearer hp_secret_token\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"reason\":\"Overdue invoice\"}'",
                'php' => "\$response = \$client->post('https://panel.example.com/api/v1/accounts/10/suspend', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n    'json' => ['reason' => 'Overdue invoice'],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/accounts/10/suspend', {\n  method: 'POST',\n  headers: { 'Authorization': 'Bearer hp_secret_token', 'Content-Type': 'application/json' },\n  body: JSON.stringify({ reason: 'Overdue invoice' })\n});",
            ],
        ],
        [
            'id' => 'accounts-unsuspend',
            'group' => 'Accounts',
            'title' => 'Unsuspend Account',
            'method' => 'POST',
            'path' => '/api/v1/accounts/{id}/unsuspend',
            'scope' => 'accounts:write',
            'description' => 'Unsuspends a previously suspended account and restores web vhost and services.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
            ],
            'request_body' => null,
            'response' => [
                'status' => 200,
                'body' => [
                    'status' => 'active',
                    'message' => 'Account reactivated successfully.',
                ],
            ],
            'samples' => [
                'curl' => "curl -X POST \"https://panel.example.com/api/v1/accounts/10/unsuspend\" \\\n  -H \"Authorization: Bearer hp_secret_token\"",
                'php' => "\$response = \$client->post('https://panel.example.com/api/v1/accounts/10/unsuspend', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/accounts/10/unsuspend', {\n  method: 'POST',\n  headers: { 'Authorization': 'Bearer hp_secret_token' }\n});",
            ],
        ],
        [
            'id' => 'accounts-upgrade',
            'group' => 'Accounts',
            'title' => 'Upgrade / Change Package',
            'method' => 'POST',
            'path' => '/api/v1/accounts/{id}/upgrade',
            'scope' => 'accounts:write',
            'description' => 'Modifies the hosting package quotas (disk quota, PHP workers, memory limit) of an account.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
                'Content-Type' => 'application/json',
            ],
            'request_body' => [
                'package_id' => 2,
            ],
            'response' => [
                'status' => 200,
                'body' => [
                    'status' => 'success',
                    'message' => 'Account package updated successfully.',
                ],
            ],
            'samples' => [
                'curl' => "curl -X POST \"https://panel.example.com/api/v1/accounts/10/upgrade\" \\\n  -H \"Authorization: Bearer hp_secret_token\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"package_id\":2}'",
                'php' => "\$response = \$client->post('https://panel.example.com/api/v1/accounts/10/upgrade', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n    'json' => ['package_id' => 2],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/accounts/10/upgrade', {\n  method: 'POST',\n  headers: { 'Authorization': 'Bearer hp_secret_token', 'Content-Type': 'application/json' },\n  body: JSON.stringify({ package_id: 2 })\n});",
            ],
        ],
        [
            'id' => 'accounts-destroy',
            'group' => 'Accounts',
            'title' => 'Terminate Account',
            'method' => 'DELETE',
            'path' => '/api/v1/accounts/{id}',
            'scope' => 'accounts:write',
            'description' => 'Enqueues account termination. Removes vhost, PHP pool, files, and databases permanently.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
            ],
            'request_body' => null,
            'response' => [
                'status' => 202,
                'body' => [
                    'status' => 'terminating',
                    'message' => 'Account termination job dispatched.',
                ],
            ],
            'samples' => [
                'curl' => "curl -X DELETE \"https://panel.example.com/api/v1/accounts/10\" \\\n  -H \"Authorization: Bearer hp_secret_token\"",
                'php' => "\$response = \$client->delete('https://panel.example.com/api/v1/accounts/10', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/accounts/10', {\n  method: 'DELETE',\n  headers: { 'Authorization': 'Bearer hp_secret_token' }\n});",
            ],
        ],
        [
            'id' => 'domains-create',
            'group' => 'Domains',
            'title' => 'Create Domain / Subdomain',
            'method' => 'POST',
            'path' => '/api/v1/domains',
            'scope' => 'domains:write',
            'description' => 'Creates a new addon domain or subdomain under an existing account.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
                'Content-Type' => 'application/json',
            ],
            'request_body' => [
                'account_id' => 10,
                'domain' => 'blog.acme.org',
                'type' => 'subdomain',
                'document_root' => 'public_html/blog',
            ],
            'response' => [
                'status' => 201,
                'body' => [
                    'domain' => [
                        'id' => 25,
                        'name' => 'blog.acme.org',
                        'type' => 'subdomain',
                        'status' => 'active',
                    ],
                ],
            ],
            'samples' => [
                'curl' => "curl -X POST \"https://panel.example.com/api/v1/domains\" \\\n  -H \"Authorization: Bearer hp_secret_token\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"account_id\":10,\"domain\":\"blog.acme.org\",\"type\":\"subdomain\"}'",
                'php' => "\$response = \$client->post('https://panel.example.com/api/v1/domains', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n    'json' => ['account_id' => 10, 'domain' => 'blog.acme.org', 'type' => 'subdomain'],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/domains', {\n  method: 'POST',\n  headers: { 'Authorization': 'Bearer hp_secret_token', 'Content-Type': 'application/json' },\n  body: JSON.stringify({ account_id: 10, domain: 'blog.acme.org', type: 'subdomain' })\n});",
            ],
        ],
        [
            'id' => 'ssl-issue',
            'group' => 'SSL Certificates',
            'title' => 'Issue Let\'s Encrypt SSL',
            'method' => 'POST',
            'path' => '/api/v1/ssl/issue',
            'scope' => 'ssl:write',
            'description' => 'Requests and deploys an automated Let\'s Encrypt TLS certificate for a domain.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
                'Content-Type' => 'application/json',
            ],
            'request_body' => [
                'domain_id' => 25,
            ],
            'response' => [
                'status' => 202,
                'body' => [
                    'status' => 'issuing',
                    'message' => 'SSL issuance task enqueued.',
                ],
            ],
            'samples' => [
                'curl' => "curl -X POST \"https://panel.example.com/api/v1/ssl/issue\" \\\n  -H \"Authorization: Bearer hp_secret_token\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"domain_id\":25}'",
                'php' => "\$response = \$client->post('https://panel.example.com/api/v1/ssl/issue', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n    'json' => ['domain_id' => 25],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/ssl/issue', {\n  method: 'POST',\n  headers: { 'Authorization': 'Bearer hp_secret_token', 'Content-Type': 'application/json' },\n  body: JSON.stringify({ domain_id: 25 })\n});",
            ],
        ],
        [
            'id' => 'accounts-usage',
            'group' => 'Accounts',
            'title' => 'Get Resource Usage',
            'method' => 'GET',
            'path' => '/api/v1/accounts/{id}/usage',
            'scope' => 'usage:read',
            'description' => 'Returns current disk usage, bandwidth consumption, database counts, and quota limits.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
            ],
            'request_body' => null,
            'response' => [
                'status' => 200,
                'body' => [
                    'disk_usage_mb' => 1420,
                    'disk_limit_mb' => 10240,
                    'bandwidth_usage_mb' => 8400,
                    'bandwidth_limit_mb' => 102400,
                    'databases_count' => 2,
                ],
            ],
            'samples' => [
                'curl' => "curl -X GET \"https://panel.example.com/api/v1/accounts/10/usage\" \\\n  -H \"Authorization: Bearer hp_secret_token\"",
                'php' => "\$response = \$client->get('https://panel.example.com/api/v1/accounts/10/usage', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/accounts/10/usage', {\n  headers: { 'Authorization': 'Bearer hp_secret_token' }\n});",
            ],
        ],
        [
            'id' => 'health-check',
            'group' => 'System',
            'title' => 'Cluster Health Check',
            'method' => 'GET',
            'path' => '/api/v1/health',
            'scope' => 'health:read',
            'description' => 'Checks panel database responsiveness and connectivity to all registered node agents.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
            ],
            'request_body' => null,
            'response' => [
                'status' => 200,
                'body' => [
                    'status' => 'healthy',
                    'panel' => ['status' => 'ok', 'database' => 'connected'],
                    'servers' => [
                        ['id' => 1, 'name' => 'Node-01', 'status' => 'online', 'agent_version' => '0.1.0'],
                    ],
                ],
            ],
            'samples' => [
                'curl' => "curl -X GET \"https://panel.example.com/api/v1/health\" \\\n  -H \"Authorization: Bearer hp_secret_token\"",
                'php' => "\$response = \$client->get('https://panel.example.com/api/v1/health', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/health', {\n  headers: { 'Authorization': 'Bearer hp_secret_token' }\n});",
            ],
        ],
        [
            'id' => 'packages-list',
            'group' => 'Packages',
            'title' => 'List Packages',
            'method' => 'GET',
            'path' => '/api/v1/packages',
            'scope' => 'packages:read',
            'description' => 'Lists available hosting plans and quota specifications.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
            ],
            'request_body' => null,
            'response' => [
                'status' => 200,
                'body' => [
                    'packages' => [
                        ['id' => 1, 'name' => 'Starter', 'storage_mb' => 5120, 'bandwidth_gb' => 50],
                        ['id' => 2, 'name' => 'Business', 'storage_mb' => 20480, 'bandwidth_gb' => 200],
                    ],
                ],
            ],
            'samples' => [
                'curl' => "curl -X GET \"https://panel.example.com/api/v1/packages\" \\\n  -H \"Authorization: Bearer hp_secret_token\"",
                'php' => "\$response = \$client->get('https://panel.example.com/api/v1/packages', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/packages', {\n  headers: { 'Authorization': 'Bearer hp_secret_token' }\n});",
            ],
        ],
        [
            'id' => 'accounts-password',
            'group' => 'Accounts',
            'title' => 'Reset Account Password',
            'method' => 'POST',
            'path' => '/api/v1/accounts/{id}/password',
            'scope' => 'accounts:write',
            'description' => 'Updates SSH, SFTP, and default database access passwords for an account.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
                'Content-Type' => 'application/json',
            ],
            'request_body' => [
                'password' => 'StrongP@ssw0rd!2026',
            ],
            'response' => [
                'status' => 200,
                'body' => [
                    'status' => 'success',
                    'message' => 'Password changed successfully.',
                ],
            ],
            'samples' => [
                'curl' => "curl -X POST \"https://panel.example.com/api/v1/accounts/10/password\" \\\n  -H \"Authorization: Bearer hp_secret_token\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"password\":\"StrongP@ssw0rd!2026\"}'",
                'php' => "\$response = \$client->post('https://panel.example.com/api/v1/accounts/10/password', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n    'json' => ['password' => 'StrongP@ssw0rd!2026'],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/accounts/10/password', {\n  method: 'POST',\n  headers: { 'Authorization': 'Bearer hp_secret_token', 'Content-Type': 'application/json' },\n  body: JSON.stringify({ password: 'StrongP@ssw0rd!2026' })\n});",
            ],
        ],
        [
            'id' => 'accounts-sso',
            'group' => 'Accounts',
            'title' => 'Generate SSO Login Token',
            'method' => 'POST',
            'path' => '/api/v1/accounts/{id}/sso',
            'scope' => 'accounts:read',
            'description' => 'Generates a one-time single sign-on redirect URL for WHMCS / client portal integrations.',
            'headers' => [
                'Authorization' => 'Bearer hp_your_token_here',
            ],
            'request_body' => null,
            'response' => [
                'status' => 200,
                'body' => [
                    'redirect_url' => 'https://panel.example.com/sso/consume?token=sso_abc123xyz',
                    'expires_in' => 300,
                ],
            ],
            'samples' => [
                'curl' => "curl -X POST \"https://panel.example.com/api/v1/accounts/10/sso\" \\\n  -H \"Authorization: Bearer hp_secret_token\"",
                'php' => "\$response = \$client->post('https://panel.example.com/api/v1/accounts/10/sso', [\n    'headers' => ['Authorization' => 'Bearer hp_secret_token'],\n]);",
                'nodejs' => "const res = await fetch('https://panel.example.com/api/v1/accounts/10/sso', {\n  method: 'POST',\n  headers: { 'Authorization': 'Bearer hp_secret_token' }\n});",
            ],
        ],
    ],
];

