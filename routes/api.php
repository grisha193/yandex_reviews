<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
Route::get('/user', [AuthController::class, 'user']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/organization', [OrganizationController::class, 'show']);
    Route::post('/organization', [OrganizationController::class, 'store']);
    Route::get('/organization/reviews', [OrganizationController::class, 'reviews']);
});
