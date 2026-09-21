<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Separate PHP process for committed MySQL concurrency tests; never uses the development DB.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'app.env' => 'testing', 'database.default' => 'mysql',
    'database.connections.mysql.database' => 'gruppa_cabinet_test',
    'cache.default' => 'array', 'integration.secret' => getenv('TEST_INTEGRATION_SECRET'),
    'integration.rate_per_minute' => 10000,
]);
DB::purge('mysql');
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$endpoint = $input['endpoint'];
$payload = json_encode($input['payload'], JSON_THROW_ON_ERROR);
$id = $input['request_id'];
$timestamp = (string) time();
$path = '/api/v1/'.$endpoint;
$signature = hash_hmac('sha256', implode("\n", ['v1', 'POST', $path, $timestamp, $id, hash('sha256', $payload)]), config('integration.secret'));
$multipart = $endpoint === 'psychologists';
$request = Request::create($path, 'POST', $multipart ? ['payload' => $payload] : [], [], [], [
    'CONTENT_TYPE' => $multipart ? 'multipart/form-data' : 'application/json',
    'HTTP_X_REQUEST_ID' => $id, 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => $signature,
], $multipart ? null : $payload);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
