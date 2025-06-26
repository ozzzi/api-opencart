<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['api'])->group(static function () {
    Route::get('/', static function () {
        return response()->json(['success' => 'true']);
    });
});
