<?php

use App\Http\Controllers\ImageController;
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


Route::post('/backend-delivery/order/prepare',[\App\Http\Controllers\OrderController::class,'prepare']);
Route::post('/backend-delivery/order/assembly',[\App\Http\Controllers\OrderController::class,'assembly']);
Route::post('/backend-delivery/order/assembly-finish',[\App\Http\Controllers\OrderController::class,'assemblyFinish']);
Route::post('/backend-delivery/order/delivery',[\App\Http\Controllers\OrderController::class,'delivery']);
Route::post('/backend-delivery/order/finish',[\App\Http\Controllers\OrderController::class,'finish']);
Route::post('/backend-delivery/order/shop/change',[\App\Http\Controllers\OrderController::class,'shopChange']);
Route::post('/backend-delivery/order/cancel',[\App\Http\Controllers\OrderController::class,'cancel']);
Route::post('/backend-delivery/order/product/add',[\App\Http\Controllers\OrderController::class,'productAdd']);

Route::post('/backend-delivery/user/delete',[\App\Http\Controllers\UserController::class,'delete']);
Route::post('/backend-delivery/notification',[\App\Http\Controllers\PushNotificationController::class,'notifyOrderStatus']);
