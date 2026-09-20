<?php

use App\Support\PrototypeCatalog;
use App\Support\PrototypeFixtures;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

if (app()->environment(['local', 'testing'])) {
    Route::prefix('_prototype')->name('prototype.')->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ])->group(function (): void {
        Route::get('/', fn () => view('prototype.index', PrototypeFixtures::catalog()))->name('index');
        foreach (PrototypeCatalog::pages() as $slug => $page) {
            Route::get('/'.$slug.'/{variant?}', function (?string $variant = null) use ($slug, $page) {
                $variant ??= $page['variants'][0];
                abort_unless(in_array($variant, $page['variants'], true), 404);
                $view = $slug === 'errors' ? 'errors.'.$variant : $page['view'];
                if ($variant === 'permission') {
                    $view = 'errors.403';
                }

                return view($view, PrototypeFixtures::page($slug, $variant));
            })->name($slug);
        }
    });
}
