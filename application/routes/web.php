<?php

use App\Http\Controllers\Admin\PsychologistController;
use App\Http\Controllers\Admin\PsychologistDocumentController;
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

Route::middleware(['account', 'role:admin'])->prefix('admin/psychologists')->name('admin.psychologists.')->group(function (): void {
    Route::get('/', [PsychologistController::class, 'index'])->name('index');
    Route::get('/create', [PsychologistController::class, 'create'])->name('create');
    Route::post('/', [PsychologistController::class, 'store'])->name('store');
    Route::get('/{psychologist}', [PsychologistController::class, 'show'])->name('show');
    Route::get('/{psychologist}/edit', [PsychologistController::class, 'edit'])->name('edit');
    Route::put('/{psychologist}', [PsychologistController::class, 'update'])->name('update');
    foreach (['approve', 'reject', 'enable', 'disable', 'tariff'] as $action) {
        Route::post('/{psychologist}/'.$action, [PsychologistController::class, 'action'])->name($action);
    }
    Route::delete('/{psychologist}', [PsychologistController::class, 'action'])->name('destroy');
    Route::get('/{psychologist}/documents', [PsychologistDocumentController::class, 'index'])->name('documents.index');
    Route::post('/{psychologist}/documents', [PsychologistDocumentController::class, 'store'])->name('documents.store');
    Route::get('/{psychologist}/documents/{document}/view', [PsychologistDocumentController::class, 'view'])->name('documents.view');
    Route::get('/{psychologist}/documents/{document}/download', [PsychologistDocumentController::class, 'download'])->name('documents.download');
    Route::delete('/{psychologist}/documents/{document}', [PsychologistDocumentController::class, 'destroy'])->name('documents.destroy');
});

if (app()->environment(['local', 'testing'])) {
    Route::get('/_foundation', function () {
        $databaseConnected = (int) DB::selectOne('SELECT 1 AS connected')?->connected === 1;

        return view('foundation', compact('databaseConnected'));
    })->name('foundation');

    Route::get('/redirect-check', fn () => redirect()->route('foundation'))->name('foundation.redirect');
}

require __DIR__.'/prototype.php';
