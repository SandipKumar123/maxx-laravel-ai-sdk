<?php

return [
    'enabled' => (bool) env('MAXX_AI_ENABLED', false),
    'allow_provisional' => (bool) env('MAXX_AI_ALLOW_PROVISIONAL', false),
    'base_url' => env('MAXX_AI_BASE_URL', 'http://127.0.0.1:8000'),
    'timeout' => 15,
    'connect_timeout' => 5,
    'machine' => [
        'private_key_path' => env('MAXX_AI_MACHINE_PRIVATE_KEY_PATH'),
        'kid' => env('MAXX_AI_MACHINE_KID'),
        'issuer' => env('MAXX_AI_MACHINE_ISSUER'),
        'subject' => env('MAXX_AI_MACHINE_SUBJECT'),
        'account_id' => env('MAXX_AI_ACCOUNT_ID'),
        'application_id' => env('MAXX_AI_APPLICATION_ID'),
        'deployment_id' => env('MAXX_AI_DEPLOYMENT_ID'),
        'permissions' => ['actions:execute', 'completion:report', 'billing:read', 'billing:ack'],
        'ttl' => 300,
    ],
    'billing' => [
        'issuer' => 'maxx-m2',
        // Explicit kid => absolute public-key-file path; retain historical verification keys.
        'public_key_paths' => [],
        'connection' => null,
    ],
];
