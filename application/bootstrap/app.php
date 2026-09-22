<?php

use App\Http\Controllers\WebpayNotifyController;
use App\Http\Middleware\EnsureAccountAccess;
use App\Http\Middleware\RequireRole;
use App\Integration\ApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Queue exception traces must never include serialized invitation payloads.
ini_set('zend.exception_ignore_args', '1');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::post('/webpay/notify', WebpayNotifyController::class)->name('webpay.notify');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(replace: [StartSession::class => App\Http\Middleware\StartSession::class]);
        // Preserve the exact signed manifest; API DTOs normalize validated fields explicitly.
        $middleware->trimStrings(except: [fn ($request) => ApiErrors::matches($request) || $request->is('webpay/notify')]);
        $middleware->convertEmptyStringsToNull(except: [fn ($request) => ApiErrors::matches($request) || $request->is('webpay/notify')]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->alias([
            'account' => EnsureAccountAccess::class,
            'role' => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', 'password', 'password_confirmation']);
        $exceptions->report(function (Throwable $exception) {
            if (request()->is('webpay/notify')) {
                Log::error('WEBPAY notify unavailable.', ['code' => 'internal_error']);

                return false;
            }
            if (request()->routeIs('password.*')) {
                Log::error('Password setup request failed.', ['exception_type' => get_class($exception)]);

                return false;
            }
            if (ApiErrors::matches(request())) {
                return false; // The renderer writes only safe technical diagnostics.
            }
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('webpay/notify')) {
                return response('Unavailable', 503);
            }
            if ($request->routeIs('password.*')) {
                $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

                return response('Не удалось выполнить запрос. Попробуйте позже или обратитесь к администратору.', $status)
                    ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
            }
            if (ApiErrors::matches($request)) {
                if ($exception instanceof HttpResponseException) {
                    return $exception->getResponse();
                }

                return ApiErrors::render($exception, $request);
            }
        });
    })->create();
