<?php

namespace App\Providers;

use App\Integration\ApiErrors;
use App\Integration\IntegrationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('password-setup', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('password-setup-resend', fn (Request $request) => Limit::perMinute(1)->by($request->user()?->id.'|'.$request->route('psychologist')));
        RateLimiter::for('integration', function (Request $request) {
            return Limit::perMinute(max(1, config('integration.rate_per_minute')))
                ->by($request->ip().'|'.$request->path())
                ->response(fn () => ApiErrors::render(new IntegrationException('rate_limited', 429), $request));
        });
    }
}
