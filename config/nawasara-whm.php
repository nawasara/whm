<?php

return [
    // Accounts per page
    'per_page' => 25,

    // Cache TTL in seconds (account list, server status)
    'cache_ttl' => 300,

    // HTTP timeout for WHM API calls (seconds) — WHM bisa lama saat listaccts
    // atau list_pops_with_disk (1000+ accounts butuh waktu untuk hitung disk usage)
    'timeout' => 60,

    // Connect timeout (TCP+TLS handshake). Server WHM kadang lambat respond
    // pas first connection (warmup), naikkan kalau sering timeout connect.
    'connect_timeout' => 30,

    // Usage threshold for "near limit" warning (percent)
    'usage_warning_threshold' => 80,
    'usage_critical_threshold' => 95,

    // Scheduler — registers whm:sync-accounts + whm:sync-emails on the
    // Laravel schedule. Set WHM_SCHEDULER_ENABLED=false to skip registering
    // them, e.g. when the deployment has no WHM API credentials yet (the
    // scheduled tasks would just fail every run and spam the log).
    'scheduler' => [
        'enabled' => env('WHM_SCHEDULER_ENABLED', true),
    ],
];
