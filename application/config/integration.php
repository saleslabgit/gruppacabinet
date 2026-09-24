<?php

return [
    'rate_per_minute' => (int) env('INTEGRATION_RATE_PER_MINUTE', 60),
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', env('INTEGRATION_ALLOWED_IPS', ''))))),
];
