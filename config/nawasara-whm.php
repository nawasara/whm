<?php

return [
    // Accounts per page
    'per_page' => 25,

    // Cache TTL in seconds (account list, server status)
    'cache_ttl' => 300,

    // HTTP timeout for WHM API calls (seconds) — WHM bisa lama saat listaccts
    'timeout' => 30,

    // Usage threshold for "near limit" warning (percent)
    'usage_warning_threshold' => 80,
    'usage_critical_threshold' => 95,
];
