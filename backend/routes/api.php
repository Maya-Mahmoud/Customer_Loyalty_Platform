<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/ping', fn () => response()->json([
        'status' => 'ok',
        'locale' => app()->getLocale(),
        'time' => now()->toIso8601String(),
    ]));

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', fn (Request $request) => $request->user());
    });
});
