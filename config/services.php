<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'agent' => [
        'secret' => env('PANEL_AGENT_HMAC_SECRET', 'test-agent-secret-key-change-me'),
        'timeout' => env('PANEL_AGENT_TIMEOUT', 30),
        'connect_timeout' => env('PANEL_AGENT_CONNECT_TIMEOUT', 5),
        'tls_ca_file' => env('PANEL_AGENT_TLS_CA_FILE'),
        'client_cert_file' => env('PANEL_AGENT_CLIENT_CERT_FILE'),
        'client_key_file' => env('PANEL_AGENT_CLIENT_KEY_FILE'),
    ],

];
