<?php

return [
    'environment' => env('WEBPAY_ENV', 'sandbox'),
    'store_id' => env('WEBPAY_STORE_ID'),
    'secret_key' => env('WEBPAY_SECRET_KEY'),
    'api_username' => env('WEBPAY_API_USERNAME'),
    'api_password' => env('WEBPAY_API_PASSWORD'),
    'http_timeout' => (int) env('WEBPAY_HTTP_TIMEOUT', 15),
];
