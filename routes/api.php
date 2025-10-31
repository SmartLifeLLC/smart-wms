<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// All API routes require API key authentication
Route::middleware('api.key')->group(function () {
    // Public routes (no user authentication required, only API key)
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Protected routes (require both API key and user authentication)
    Route::middleware('auth:sanctum')->group(function () {
        // Authentication
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // Master data endpoints will be added here

        // Picking task endpoints will be added here
    });
});
