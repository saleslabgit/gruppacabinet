<?php

use App\Http\Controllers\Admin\DictionaryController;
use App\Http\Controllers\Admin\DictionaryItemController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PsychologistController;
use App\Http\Controllers\Admin\PsychologistDocumentController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PasswordSetupController;
use App\Http\Controllers\Psychologist\ApplicationController;
use App\Http\Controllers\Psychologist\GroupController;
use App\Http\Controllers\Psychologist\ProfileController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:password-setup')->group(function (): void {
    Route::get('/password/setup/{token}', [PasswordSetupController::class, 'show'])->name('password.setup');
    Route::post('/password/setup', [PasswordSetupController::class, 'store'])->name('password.store');
});

Route::get('/login', [SessionController::class, 'create'])->name('login');
Route::post('/login', [SessionController::class, 'store'])->name('login.store');
Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

Route::middleware('account')->group(function (): void {
    Route::get('/', [GroupController::class, 'index'])->middleware('role:psychologist')->name('psychologist.home');
    Route::get('/admin', [HomeController::class, 'admin'])->middleware('role:admin')->name('admin.home');
});

Route::middleware(['account', 'role:psychologist'])->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'show'])->name('psychologist.profile');
    Route::get('/profile/documents/{document}/view', [ProfileController::class, 'view'])->name('psychologist.documents.view');
    Route::get('/profile/documents/{document}/download', [ProfileController::class, 'download'])->name('psychologist.documents.download');
});

Route::middleware(['account', 'role:admin'])->prefix('admin/psychologists')->name('admin.psychologists.')->group(function (): void {
    Route::get('/', [PsychologistController::class, 'index'])->name('index');
    Route::get('/create', [PsychologistController::class, 'create'])->name('create');
    Route::post('/', [PsychologistController::class, 'store'])->name('store');
    Route::get('/{psychologist}', [PsychologistController::class, 'show'])->name('show');
    Route::post('/{psychologist}/password-setup', [PsychologistController::class, 'passwordSetup'])->middleware('throttle:password-setup-resend')->name('password-setup');
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

Route::middleware(['account', 'role:psychologist'])->prefix('groups')->name('psychologist.groups.')->group(function (): void {
    $controller = GroupController::class;
    Route::post('/', [$controller, 'store'])->name('store');
    Route::get('/{group}', [$controller, 'show'])->name('show');
    Route::get('/{group}/edit', [$controller, 'edit'])->name('edit');
    Route::put('/{group}', [$controller, 'update'])->name('update');
    Route::post('/{group}/submit', [$controller, 'update'])->name('submit');
    Route::get('/{group}/extension', [$controller, 'extension'])->name('extension');
    Route::post('/{group}/extension', [$controller, 'extend'])->name('extend');
    Route::delete('/{group}', [$controller, 'destroy'])->name('destroy');
    $applications = ApplicationController::class;
    Route::get('/{group}/applications', [$applications, 'index'])->name('applications.index');
    Route::get('/{group}/applications/{application}', [$applications, 'show'])->name('applications.show');
    Route::post('/{group}/applications/{application}/processed', [$applications, 'action'])->name('applications.processed');
    Route::post('/{group}/applications/{application}/unprocessed', [$applications, 'action'])->name('applications.unprocessed');
});

Route::middleware(['account', 'role:admin'])->prefix('admin/applications')->name('admin.applications.')->group(function (): void {
    $controller = App\Http\Controllers\Admin\ApplicationController::class;
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('/{application}', [$controller, 'show'])->name('show');
});

Route::middleware(['account', 'role:admin'])->prefix('admin/groups')->name('admin.groups.')->group(function (): void {
    $controller = App\Http\Controllers\Admin\GroupController::class;
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('/create', [$controller, 'create'])->name('create');
    Route::post('/', [$controller, 'store'])->name('store');
    Route::get('/{group}', [$controller, 'show'])->name('show');
    Route::get('/{group}/edit', [$controller, 'edit'])->name('edit');
    Route::put('/{group}', [$controller, 'update'])->name('update');
    foreach (['approve', 'revision', 'reject', 'activate'] as $action) {
        Route::post('/{group}/'.$action, [$controller, 'action'])->name($action);
    }
    Route::delete('/{group}', [$controller, 'action'])->name('destroy');
});

Route::middleware(['account', 'role:admin'])->prefix('admin')->name('admin.')->scopeBindings()->group(function (): void {
    $dictionaries = DictionaryController::class;
    $items = DictionaryItemController::class;
    Route::get('/dictionaries', [$dictionaries, 'index'])->name('dictionaries.index');
    Route::post('/dictionaries', [$dictionaries, 'store'])->name('dictionaries.store');
    Route::get('/dictionaries/{dictionary}/edit', [$dictionaries, 'index'])->name('dictionaries.edit');
    Route::put('/dictionaries/{dictionary}', [$dictionaries, 'update'])->name('dictionaries.update');
    Route::delete('/dictionaries/{dictionary}', [$dictionaries, 'destroy'])->name('dictionaries.destroy');
    Route::get('/dictionaries/{dictionary}/items', [$items, 'index'])->name('dictionaries.items.index');
    Route::post('/dictionaries/{dictionary}/items', [$items, 'store'])->name('dictionaries.items.store');
    Route::get('/dictionaries/{dictionary}/items/{item}/edit', [$items, 'index'])->name('dictionaries.items.edit');
    Route::put('/dictionaries/{dictionary}/items/{item}', [$items, 'update'])->name('dictionaries.items.update');
    foreach (['activate', 'deactivate'] as $action) {
        Route::post('/dictionaries/{dictionary}/items/{item}/'.$action, [$items, 'action'])->name('dictionaries.items.'.$action);
    }
    Route::delete('/dictionaries/{dictionary}/items/{item}', [$items, 'action'])->name('dictionaries.items.destroy');
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])->name('payments.refund');
});

if (app()->environment(['local', 'testing'])) {
    Route::get('/_foundation', function () {
        $databaseConnected = (int) DB::selectOne('SELECT 1 AS connected')?->connected === 1;

        return view('foundation', compact('databaseConnected'));
    })->name('foundation');

    Route::get('/redirect-check', fn () => redirect()->route('foundation'))->name('foundation.redirect');
}

Route::middleware(['account', 'role:psychologist'])->prefix('payments')->name('psychologist.payments.')->group(function (): void {
    $controller = App\Http\Controllers\Psychologist\PaymentController::class;
    Route::get('/{payment}', [$controller, 'show'])->name('show');
    Route::get('/{payment}/return', [$controller, 'result'])->name('return');
    Route::get('/{payment}/cancel', [$controller, 'result'])->name('cancel');
    Route::post('/{payment}/start', [$controller, 'start'])->name('start');
    Route::post('/{payment}/retry', [$controller, 'retry'])->name('retry');
});

require __DIR__.'/prototype.php';
