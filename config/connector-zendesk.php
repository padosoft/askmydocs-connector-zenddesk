<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    | Timeouts and caps for every call to https://{subdomain}.zendesk.com/api/v2.
    | A cursor-based incremental export page can carry up to 1000 full ticket
    | objects, hence the generous JSON cap. `resolve_dns=false` is for faked
    | HTTP in tests only: production always pins the resolved public address.
    */
    'http' => [
        'timeout' => 30,
        'connect_timeout' => 5,
        'max_json_bytes' => 16_000_000,
        'resolve_dns' => true,
        'attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    | Hosts an attachment `content_url` (and every redirect hop) may point to.
    | Credentials are sent ONLY to the account's own subdomain, never to a CDN.
    */
    'attachments' => [
        'allowed_hosts' => [
            '*.zendesk.com',
            '*.zdusercontent.com',
            '*.zdassets.com',
            's3.amazonaws.com',
            '*.s3.amazonaws.com',
            '*.s3.*.amazonaws.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync
    |--------------------------------------------------------------------------
    | `batch_items` / `batch_seconds` bound one queued batch. `overlap_seconds`
    | is re-scanned before the last completed watermark so a write that landed
    | while the previous scan was running is not missed. `export_min_age_seconds`
    | keeps the incremental export start_time safely in the past (Zendesk
    | rejects a start_time that is too recent).
    */
    'sync' => [
        'batch_items' => 5,
        'batch_seconds' => 60,
        'queue' => env('CONNECTOR_ZENDESK_QUEUE', 'zendesk'),
        'connection' => env('CONNECTOR_ZENDESK_CONNECTION'),
        'overlap_seconds' => 120,
        'export_min_age_seconds' => 90,
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat tools
    |--------------------------------------------------------------------------
    */
    'chat_tools' => [
        'enabled' => true,
        'max_result_bytes' => 65_536,
    ],
];
