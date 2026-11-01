<?php

use App\Http\Controllers\Pickup\CdekPvzController;
use App\Http\Controllers\Pickup\CdekTariffsController;
use App\Http\Controllers\Pickup\IntegrationConfigsController;
use App\Http\Controllers\Pickup\PickupCitiesController;
use App\Http\Controllers\Pickup\RpOfficesController;
use App\Http\Controllers\Pickup\RpTariffsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix('v1/slots')->group(function () {
    Route::get('cdek-pvz', [CdekPvzController::class, 'index']);
    Route::get('cdek-pvz/{code}', [CdekPvzController::class, 'show']);

    Route::get('rp-offices', [RpOfficesController::class, 'index']);
    Route::get('rp-offices/{index}', [RpOfficesController::class, 'show'])->where('index', '\d{6}');

    Route::get('pickup-cities', [PickupCitiesController::class, 'index']);

    Route::get('delivery/cdek/tariffs', [CdekTariffsController::class, 'index']);
    Route::get('delivery/rp/tariffs', [RpTariffsController::class, 'index']);

    Route::get('integration-configs/{shopId}', [IntegrationConfigsController::class, 'show']);
});
