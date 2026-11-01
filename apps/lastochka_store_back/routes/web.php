<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\UploadController;
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

//Подтверждение почты
Route::get('/action/confirm/{token}', [AuthController::class, 'verifyEmail'])->name('email.confirm');

// Получение изображения
Route::get('/action/img/{id}', [ImageController::class, 'get']);

// Запросы editor JS
Route::get('/action/fetchlink', [UploadController::class, 'fetchLink'])->name('editor_fetch');
Route::post('/action/uploadeditor', [UploadController::class, 'uploadEditor'])->name('editor_editor');
