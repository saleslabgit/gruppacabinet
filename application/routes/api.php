<?php

use App\Http\Controllers\Api\IntakeController;
use App\Http\Middleware\ValidateIntegrationRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['throttle:integration', ValidateIntegrationRequest::class])->group(function (): void {
    Route::post('psychologists', [IntakeController::class, 'psychologist']);
    Route::post('group-applications', [IntakeController::class, 'application']);
});
