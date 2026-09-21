<?php

use App\Http\Controllers\Api\IntakeController;
use App\Http\Middleware\AuthenticateIntegration;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['throttle:integration', AuthenticateIntegration::class])->group(function (): void {
    Route::post('psychologists', [IntakeController::class, 'psychologist']);
    Route::post('group-applications', [IntakeController::class, 'application']);
});
