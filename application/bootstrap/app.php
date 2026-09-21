<?php

use App\Http\Middleware\EnsureAccountAccess;
use App\Http\Middleware\RequireRole;
use App\Integration\ApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Preserve the exact signed manifest; API DTOs normalize validated fields explicitly.
        $middleware->trimStrings(except: [fn ($request) => ApiErrors::matches($request)]);
        $middleware->convertEmptyStringsToNull(except: [fn ($request) => ApiErrors::matches($request)]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->alias([
            'account' => EnsureAccountAccess::class,
            'role' => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception) {
            if (ApiErrors::matches(request())) {
                return false; // The renderer writes only safe technical diagnostics.
            }
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (ApiErrors::matches($request)) {
                if ($exception instanceof HttpResponseException) {
                    return $exception->getResponse();
                }

                return ApiErrors::render($exception, $request);
            }
        });
    })->create();
