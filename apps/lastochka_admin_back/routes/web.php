<?php

use App\Http\Controllers\DevLoginController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\ProductExportController;
use App\Http\Controllers\QuarantineExportController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\Webhooks\CdekWebhookController;
use App\Services\DevLoginService;
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


// Получение изображения
Route::get('/action/img/{id}', [ImageController::class, 'get']);

// Webhook от CDEK — CSRF отключён в VerifyCsrfToken::$except
// URL зарегистрирован в CDEK через RegisterCdekWebhook / cdek:register-webhook
Route::post('/action/webhooks/cdek', [CdekWebhookController::class, 'handle']);

// EditorJS: загрузка изображений и парсинг ссылок
Route::post('/action/uploadeditor', [UploadController::class, 'uploadEditor'])->name('editor_upload');
Route::get('/action/fetchlink', [UploadController::class, 'fetchLink'])->name('editor_fetch');

// Экспорт «Карантина товаров» в XLSX с учётом фильтров страницы (FOODLINK-11194)
Route::get('/action/export/quarantine', [QuarantineExportController::class, 'download'])
  ->middleware('auth:api');

// Экспорт товаров в XLSX по аналогии с заказами: выделенные product_ids
// либо весь набор фильтров экрана «Товары»
Route::post('/action/export/products', [ProductExportController::class, 'export'])
  ->middleware('auth:api');

// Dev-вход в админку по одноразовой подписанной ссылке (php artisan dev:login-link <email>).
// Роут регистрируется только при DEV_LOGIN_ENABLED=true; контроллер на рантайме
// дополнительно проверяет флаг и хост APP_URL в DEV_LOGIN_HOSTS (route:cache на деплое).
if (app(DevLoginService::class)->isEnabled()) {
  Route::get('/action/dev-login/{nonce}', DevLoginController::class)
    ->name(DevLoginService::ROUTE_NAME);
}
