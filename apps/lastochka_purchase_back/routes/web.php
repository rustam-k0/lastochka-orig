<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Экспорт заказов в Excel
Route::post('/purchase/admin/orders/export', [\App\Http\Controllers\OrderExportController::class, 'export'])
    ->middleware('auth:api');
