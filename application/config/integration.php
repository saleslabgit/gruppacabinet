<?php

return [
    'secret' => env('INTEGRATION_SECRET'),
    'timestamp_tolerance' => (int) env('INTEGRATION_TIMESTAMP_TOLERANCE', 300),
    'rate_per_minute' => (int) env('INTEGRATION_RATE_PER_MINUTE', 60),
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', env('INTEGRATION_ALLOWED_IPS', ''))))),
];
