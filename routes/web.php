<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminLeadController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ConversationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\KbController;
use App\Http\Controllers\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.login'));

Route::prefix('admin')->name('admin.')->group(function () {

    // Guest-only auth routes
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.post');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    // Authenticated admin routes
    Route::middleware('admin.auth')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::resource('conversations', ConversationController::class)
            ->only(['index', 'show']);

        Route::resource('kb', KbController::class);

        Route::resource('leads', AdminLeadController::class)
            ->only(['index', 'update']);

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    });
});
