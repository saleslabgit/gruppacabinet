<?php

namespace App\Http\Middleware;

use App\Integration\ApiErrors;
use App\Integration\IntegrationException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegration
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('X-Request-Id')) {
            throw new IntegrationException('missing_request_id', 400);
        }
        $id = ApiErrors::requestId($request);
        if ($id === null) {
            throw new IntegrationException('invalid_request_id', 400);
        }
        $timestamp = $request->header('X-Timestamp');
        if ($timestamp === null) {
            throw new IntegrationException('missing_timestamp', 401);
        }
        if (! preg_match('/\A[1-9][0-9]{0,10}\z/', $timestamp)) {
            throw new IntegrationException('invalid_timestamp', 401);
        }
        if (abs(now()->timestamp - (int) $timestamp) > config('integration.timestamp_tolerance')) {
            throw new IntegrationException('expired_timestamp', 401);
        }
        $signature = $request->header('X-Signature');
        if ($signature === null) {
            throw new IntegrationException('missing_signature', 401);
        }
        $secret = config('integration.secret');
        if (! is_string($secret) || $secret === '') {
            throw new IntegrationException('integration_unavailable', 503);
        }
        $ips = config('integration.allowed_ips');
        if ($ips !== [] && ! in_array($request->ip(), $ips, true)) {
            throw new IntegrationException('source_not_allowed', 403);
        }
        $multipart = $request->is('api/v1/psychologists');
        $type = strtolower(explode(';', $request->header('Content-Type', ''))[0]);
        if ($type !== ($multipart ? 'multipart/form-data' : 'application/json')) {
            throw new IntegrationException('unsupported_media_type', 415);
        }
        $payload = $multipart ? ($request->request->all()['payload'] ?? null) : $request->getContent();
        if (! is_string($payload)) {
            throw new IntegrationException('invalid_signature', 401);
        }
        $envelope = implode("\n", ['v1', $request->method(), '/'.$request->path(), $timestamp, $id, hash('sha256', $payload)]);
        if (! preg_match('/\A[a-f0-9]{64}\z/', $signature) || ! hash_equals(hash_hmac('sha256', $envelope, $secret), $signature)) {
            throw new IntegrationException('invalid_signature', 401);
        }
        if ($request->query->count() !== 0) {
            throw new IntegrationException('validation_failed', 422, ['request' => ['Query parameters are not accepted.']]);
        }

        return $next($request);
    }
}
