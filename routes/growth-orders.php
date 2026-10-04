<?php

use App\Http\Controllers\Growth\LaunchChecklistController;
use App\Http\Controllers\Growth\OrderPortalController;
use App\Http\Middleware\GrowthInsightsPrivacy;
use Illuminate\Support\Facades\Route;

Route::prefix('crm/portal/sites/{site}')->middleware(['auth:api', 'throttle:120,1', GrowthInsightsPrivacy::class])->group(function () {
    Route::get('deals/{deal}', [OrderPortalController::class, 'manage']);
    Route::post('deals/{deal}/plan', [OrderPortalController::class, 'save']);
    Route::post('deals/{deal}/invite', [OrderPortalController::class, 'invite']);
    Route::post('deals/{deal}/revoke', [OrderPortalController::class, 'revoke']);
    Route::get('launch', [LaunchChecklistController::class, 'show']);
    Route::post('launch', [LaunchChecklistController::class, 'update']);
});
Route::prefix('growth/orders')->middleware(['throttle:60,1', GrowthInsightsPrivacy::class])->group(function () {
    Route::get('invitation/{token}', [OrderPortalController::class, 'invitation']);
    Route::post('exchange', [OrderPortalController::class, 'exchange'])->middleware('throttle:20,1');
    Route::get('session', [OrderPortalController::class, 'show']);
    Route::get('documents/{id}', [OrderPortalController::class, 'document']);
});
