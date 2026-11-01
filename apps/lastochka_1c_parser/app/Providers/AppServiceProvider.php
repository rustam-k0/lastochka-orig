<?php

namespace App\Providers;

use App\Models\IntegrationConfig;
use App\Observers\IntegrationConfigObserver;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mavsan\LaProtocol\Http\Controllers\CatalogController;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerShopRoute();

        IntegrationConfig::observe(IntegrationConfigObserver::class);
    }

    /**
     * Регистрация роута /1c-exchange/{shop_code}
     *
     * Должен быть зарегистрирован ДО роута пакета mavsan (/1c-exchange),
     * т.к. URL вида /1c-exchange/aladdin-main более специфичен.
     */
    private function registerShopRoute(): void
    {
        Route::match(['get', 'post'], '/1c-exchange/{shop_code}',
            [CatalogController::class, 'catalogIn']
        )
        ->middleware([
            \App\Http\Middleware\ResolveShopContext::class,
            \App\Http\Middleware\BasicAuthFromConfig::class,
            \App\Http\Middleware\ProcessOrdersAfterUnzip::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ])
        ->name('1c-exchange-shop');
    }
}
