<?php

return [
    'operator' => [
        // The single operator account auto-provisioned on first boot (token-only auth).
        'email' => env('OPERATOR_EMAIL', 'admin@watchtower.local'),
    ],

    'apps' => [
        // Minutes since the last received snapshot after which an app is "stale".
        'stale_after' => (int) env('APPS_STALE_AFTER_MINUTES', 15),

        'events' => [
            // Per-type alert tier. 'critical' pages via ntfy; 'warning' is recorded but quiet.
            'severity' => [
                'exception' => env('APP_EVENT_SEVERITY_EXCEPTION', 'critical'),
                'failed_job' => env('APP_EVENT_SEVERITY_FAILED_JOB', 'critical'),
                'failed_scheduled_task' => env('APP_EVENT_SEVERITY_FAILED_SCHEDULED_TASK', 'warning'),
            ],
            // Minutes of silence after which a firing app-event alert auto-resolves.
            'quiet_after' => (int) env('APP_EVENT_QUIET_AFTER_MINUTES', 60),
            // Minutes before a still-firing app-event alert re-pages.
            'renotify_after' => (int) env('APP_EVENT_RENOTIFY_AFTER_MINUTES', 60),
            // Days to keep grouped app events before pruning.
            'retention_days' => (int) env('APP_EVENT_RETENTION_DAYS', 120),
        ],

        'metrics' => [
            'retention_days' => (int) env('APP_METRIC_RETENTION_DAYS', 30),
        ],
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
