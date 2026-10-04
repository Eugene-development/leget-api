<?php

use App\Http\Controllers\Growth\InsightsController;
use App\Http\Middleware\GrowthInsightsPrivacy;
use Illuminate\Support\Facades\Route;

Route::prefix('crm/growth/sites/{site}')->middleware(['auth:api', GrowthInsightsPrivacy::class, 'throttle:120,1'])->group(function () {
    $c = InsightsController::class;
    Route::get('/insights', [$c, 'insights']);
    Route::get('/automation', [$c, 'automation']);
    Route::post('/automation', [$c, 'saveRule'])->middleware('throttle:30,1');
    Route::post('/automation/{id}', [$c, 'saveRule'])->middleware('throttle:30,1');
    Route::get('/metrika', [$c, 'metrika']);
    Route::post('/metrika', [$c, 'saveMetrika'])->middleware('throttle:20,1');
    Route::post('/metrika/{id}/retry', [$c, 'retryDelivery'])->middleware('throttle:20,1');
});
