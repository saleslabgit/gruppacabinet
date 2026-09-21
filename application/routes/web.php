<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/login', [SessionController::class, 'create'])->name('login');
Route::post('/login', [SessionController::class, 'store'])->name('login.store');
Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

Route::middleware('account')->group(function (): void {
    Route::get('/', [HomeController::class, 'psychologist'])->middleware('role:psychologist')->name('psychologist.home');
    Route::get('/admin', [HomeController::class, 'admin'])->middleware('role:admin')->name('admin.home');
});

if (app()->environment(['local', 'testing'])) {
    Route::get('/_foundation', function () {
        $databaseConnected = (int) DB::selectOne('SELECT 1 AS connected')?->connected === 1;

        return view('foundation', compact('databaseConnected'));
    })->name('foundation');

    Route::get('/redirect-check', fn () => redirect()->route('foundation'))->name('foundation.redirect');
}

require __DIR__.'/prototype.php';
