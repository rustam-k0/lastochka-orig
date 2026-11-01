<?php

use App\Http\Controllers\DevLoginController;
use App\Http\Controllers\Web\SharedEstimateController;
use App\Http\Middleware\RabbitDebugMiddleware;
use App\Services\DevLoginService;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Публичная страница сметы — целевой адрес универсальной ссылки /estimates/shared/*
// (API-эндпоинт с JSON остаётся на /api/v1/estimates/shared/{token})
Route::get('/estimates/shared/{token}', SharedEstimateController::class)->name('estimates.shared');

// Deep Links: iOS Universal Links & Android App Links (значения per-fork в .env, см. config/deeplinks.php)
Route::get('/.well-known/apple-app-site-association', function () {
    $appIds = config('deeplinks.ios.app_ids');
    abort_if(empty($appIds), 404);

    return response()->json([
        'applinks' => [
            'details' => [
                [
                    'appIDs' => $appIds,
                    'components' => array_map(
                        fn (string $path) => ['/' => $path],
                        config('deeplinks.ios.paths')
                    ),
                ],
            ],
        ],
    ], 200, [], JSON_UNESCAPED_SLASHES);
});

Route::get('/.well-known/assetlinks.json', function () {
    $package = config('deeplinks.android.package');
    abort_if($package === '', 404);

    return response()->json([
        [
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => config('deeplinks.android.sha256_cert_fingerprints'),
            ],
        ],
    ], 200, [], JSON_UNESCAPED_SLASHES);
});

// Инструкция по удалению аккаунта (требование сторов мобильных приложений)
Route::view('/app/instruction/delete', 'app.instruction-delete');

// RabbitMQ Debug Dashboard
Route::get('/debug/rabbit', function () {
    return view('debug.rabbit');
})->middleware(RabbitDebugMiddleware::class);

// Dev-вход в веб-витрину по одноразовой подписанной ссылке (php artisan dev:login-link <phone>).
// Роут регистрируется только при DEV_LOGIN_ENABLED=true; контроллер на рантайме
// дополнительно проверяет флаг и хост APP_URL в DEV_LOGIN_HOSTS (route:cache на деплое).
if (app(DevLoginService::class)->isEnabled()) {
    Route::get('/dev-login/{nonce}', DevLoginController::class)
        ->name(DevLoginService::ROUTE_NAME);
}
