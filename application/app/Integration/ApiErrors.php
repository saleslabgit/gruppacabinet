<?php

namespace App\Integration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiErrors
{
    public static function matches(Request $request): bool
    {
        return $request->is('api/v1', 'api/v1/*');
    }

    public static function requestId(Request $request): ?string
    {
        $id = $request->header('X-Request-Id');

        return is_string($id) && preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9_.:-]{0,127}\z/', $id) ? $id : null;
    }

    public static function render(Throwable $exception, Request $request): JsonResponse
    {
        $status = $exception instanceof IntegrationException ? $exception->status : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);
        $code = $exception instanceof IntegrationException ? $exception->errorCode : match ($status) {
            404 => 'not_found', 405 => 'method_not_allowed', 413 => 'payload_too_large', default => 'internal_error',
        };
        $body = ['code' => $code, 'message' => str_replace('_', ' ', ucfirst($code)).'.'];
        if ($exception instanceof IntegrationException && $exception->errors !== []) {
            $body['errors'] = $exception->errors;
        }
        $body['request_id'] = self::requestId($request);
        // Never pass the exception object/message/trace arguments: SQL exceptions may contain PII.
        Log::warning('Integration request rejected', [
            'endpoint' => match ($request->path()) {
                'api/v1/psychologists' => 'psychologists',
                'api/v1/group-applications' => 'group-applications',
                default => 'unknown',
            },
            'request_id' => self::requestId($request), 'code' => $code,
            'ip' => $request->ip(), 'time' => now('UTC')->toIso8601String(),
            'exception_class' => $exception::class,
            'exception_file' => $exception->getFile(), 'exception_line' => $exception->getLine(),
        ]);

        return response()->json($body, $status);
    }
}
