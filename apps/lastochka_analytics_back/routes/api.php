<?php

use App\Http\Controllers\Api\V1\MeStatsController;
use App\Http\Controllers\Api\V1\StoreStatsController;
use Illuminate\Support\Facades\Route;

/*
| API аналитики B2E (#11595). Auth: Bearer Sanctum-токен сотрудника
| курьерской системы (middleware auth.staff, docker-БД).
*/
Route::middleware(['auth.staff', 'throttle:120,1'])
    ->prefix('v1/analytics')
    ->group(function (): void {
        Route::get('me', [MeStatsController::class, 'me']);
        Route::get('me/achievements', [MeStatsController::class, 'achievements']);
        Route::get('employees', [StoreStatsController::class, 'employees']);
        Route::get('store/summary', [StoreStatsController::class, 'summary']);
    });
