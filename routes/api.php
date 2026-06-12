<?php

declare(strict_types=1);

use App\Http\Controllers\Chat\ChatLeadController;
use App\Http\Controllers\Chat\FeedbackController;
use App\Http\Controllers\Chat\HealthController;
use App\Http\Controllers\Chat\HistoryController;
use App\Http\Controllers\Chat\MessageController;
use App\Http\Controllers\Chat\SessionController;
use App\Http\Controllers\SearchController;
use App\Http\Middleware\RestrictIpToHost;
use App\Http\Middleware\TokenAuth;
use Illuminate\Support\Facades\Route;

// Existing authenticated API (TokenAuth + RestrictIpToHost via api middleware group)
Route::middleware(['api'])->group(static function () {
    Route::get('/', static function () {
        return response()->json(['success' => 'true']);
    });

    Route::post('search', SearchController::class);
});

// Chat widget API — public, no TokenAuth/RestrictIpToHost
Route::prefix('chat')
    ->name('chat.')
    ->withoutMiddleware([TokenAuth::class, RestrictIpToHost::class])
    ->group(function () {
        Route::post('session', SessionController::class)
            ->name('session')
            ->middleware('throttle:5,1');

        Route::get('health', HealthController::class)->name('health');

        Route::middleware('chat.session')->group(function () {
            Route::post('message', MessageController::class)->name('message');
            Route::get('history', HistoryController::class)->name('history');
            Route::post('feedback', FeedbackController::class)->name('feedback');
            Route::post('leads', ChatLeadController::class)->name('leads');
        });
    });
