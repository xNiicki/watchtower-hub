<?php

return [
    'operator' => [
        // The single operator account auto-provisioned on first boot (token-only auth).
        'email' => env('OPERATOR_EMAIL', 'admin@watchtower.local'),
    ],

    'apps' => [
        // Minutes since the last received snapshot after which an app is "stale"
        // (shown unhealthy). Defaults to 3x the satellite's default 5-min interval.
        'stale_after' => (int) env('APPS_STALE_AFTER_MINUTES', 15),
    ],

    'ntfy' => [
        'base_url' => env('NTFY_BASE_URL'),           // e.g. http://192.168.1.30
        'topic' => env('NTFY_TOPIC', 'watchtower'),
        'token' => env('NTFY_TOKEN'),                  // optional Bearer auth
    ],

    'proxmox' => [
        // e.g. https://192.168.1.2:8006 — token needs the PVEAuditor role
        'base_url' => env('PROXMOX_BASE_URL'),
        'token_id' => env('PROXMOX_TOKEN_ID'),       // e.g. watchtower@pam!hub
        'token_secret' => env('PROXMOX_TOKEN_SECRET'),
        'verify_tls' => env('PROXMOX_VERIFY_TLS', false), // self-signed homelab default
    ],

    'pbs' => [
        'base_url' => env('PBS_BASE_URL'),            // e.g. https://192.168.1.3:8007
        'token_id' => env('PBS_TOKEN_ID'),            // e.g. watchtower@pbs!hub
        'token_secret' => env('PBS_TOKEN_SECRET'),
        'verify_tls' => env('PBS_VERIFY_TLS', false),
    ],
];
