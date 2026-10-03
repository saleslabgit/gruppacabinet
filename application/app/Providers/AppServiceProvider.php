<?php

namespace App\Providers;

use App\Http\Controllers\PasswordRecoveryController;
use App\Integration\ApiErrors;
use App\Integration\IntegrationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        RateLimiter::for('feedback', fn (Request $request) => Limit::perMinute(2)->by((string) $request->user()?->id));
        RateLimiter::for('password-recovery', function (Request $request) {
            $email = is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : '';
            $response = fn (Request $request, array $headers) => app(PasswordRecoveryController::class)->rateLimited($headers);

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip())->response($response),
                Limit::perMinute(1)->by('email:'.hash('sha256', $email))->response($response),
            ];
        });
        RateLimiter::for('password-setup', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('password-setup-resend', fn (Request $request) => Limit::perMinute(1)->by($request->user()?->id.'|'.$request->route('psychologist')));
        RateLimiter::for('integration', function (Request $request) {
            return Limit::perMinute(max(1, config('integration.rate_per_minute')))
                ->by($request->ip().'|'.$request->path())
                ->response(fn () => ApiErrors::render(new IntegrationException('rate_limited', 429), $request));
        });
    }
}
