<?php

use App\Http\Controllers\PagePublicationController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// Bearer-only editor API: cookie sessions cannot authorize these operations.
Route::prefix('growth/sites/{site}/publication')->middleware(['auth:api', 'throttle:120,1'])
    ->withoutMiddleware(ValidateCsrfToken::class)->group(function () {
        $c = PagePublicationController::class;
        Route::get('/', [$c, 'state']);
        Route::post('/drafts', [$c, 'begin']);
        Route::post('/drafts/{draft}', [$c, 'edit']);
        Route::post('/drafts/{draft}/{action}', [$c, 'action'])->whereIn('action', ['preview', 'publish', 'discard']);
        Route::post('/revisions/{revision}/restore', [$c, 'restore']);
    });
