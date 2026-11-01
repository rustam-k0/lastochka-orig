<?php

namespace App\NetworkCatalog;

use App\NetworkCatalog\Support\NetworkCatalogMode;
use Illuminate\Support\ServiceProvider;

/**
 * Точка подключения сетевого каталога (network_catalog).
 *
 * Регистрируется в config/app.php всегда. Всё, что меняет поведение существующих
 * сценариев, включается в boot() только при network_catalog=true. В форках с
 * выключенным флагом провайдер no-op (паттерн AggregatorServiceProvider).
 */
class NetworkCatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NetworkCatalogMode::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([\App\NetworkCatalog\Console\Commands\NetworkCatalogResetCommand::class]);
        }

        if (!$this->app->make(NetworkCatalogMode::class)->isEnabled()) {
            return;
        }

        // Сетевой режим: карточка товара глобальна — дедуп по (source, external_id)
        // без shop_code. Механизм global_scope пакета laravel-rabbitmq-events
        // (EventConsumer::206-227). Читается консьюмером в рантайме, после boot.
        config(['rabbitmq-events.entities.products.global_scope' => true]);
    }
}
