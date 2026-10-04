<?php

use App\Http\Controllers\Growth\EstimateController;
use App\Http\Controllers\Growth\SelectionsController;
use Illuminate\Support\Facades\Route;

Route::prefix('growth')->middleware('throttle:60,1,growth-public-')->group(function () {
    Route::get('/selection-catalog', [SelectionsController::class, 'catalog']);
    Route::post('/selections', [SelectionsController::class, 'store'])->middleware('throttle:10,1,growth-selection-create-');
    Route::get('/selections/{token}', [SelectionsController::class, 'show'])->where('token', '[a-f0-9]{48}');
    Route::post('/selections/{token}/comments', [SelectionsController::class, 'comment'])->where('token', '[a-f0-9]{48}')->middleware('throttle:10,1,growth-selection-comment-');
    Route::post('/selections/{token}/revoke', [SelectionsController::class, 'revoke'])->where('token', '[a-f0-9]{48}');
    Route::get('/estimate', [EstimateController::class, 'publicSettings']);
    Route::post('/estimate', [EstimateController::class, 'calculate'])->middleware('throttle:30,1,growth-estimate-');
});
// Register before the existing /crm/sites/{site}/{resource} catch-all.
Route::middleware(['auth:api', 'throttle:60,1'])->group(function () {
    Route::get('/crm/sites/{site}/calculator', [EstimateController::class, 'settings']);
    Route::post('/crm/sites/{site}/calculator', [EstimateController::class, 'saveSettings']);
});
