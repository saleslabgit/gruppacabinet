<?php

namespace App\Http\Middleware;

use App\Integration\ApiErrors;
use App\Integration\IntegrationException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateIntegrationRequest
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
        $ips = config('integration.allowed_ips');
        if ($ips !== [] && ! in_array($request->ip(), $ips, true)) {
            throw new IntegrationException('source_not_allowed', 403);
        }
        $multipart = $request->is('api/v1/psychologists');
        $type = strtolower(explode(';', $request->header('Content-Type', ''))[0]);
        if ($type !== ($multipart ? 'multipart/form-data' : 'application/json')) {
            throw new IntegrationException('unsupported_media_type', 415);
        }
        if ($request->query->count() !== 0) {
            throw new IntegrationException('validation_failed', 422, ['request' => ['Query parameters are not accepted.']]);
        }

        return $next($request);
    }
}
