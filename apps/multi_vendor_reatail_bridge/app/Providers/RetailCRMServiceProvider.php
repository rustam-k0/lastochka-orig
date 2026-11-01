<?php

namespace App\Providers;

use App\Services\RetailCRM\Http\RetailCrmClient;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Support\ServiceProvider;

class RetailCRMServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Регистрируем клиент RetailCRM API как синглтон
        $this->app->singleton(RetailCrmClient::class, function ($app) {
            $config = $app['config']['retailcrm'];
            
            return new RetailCrmClient(
                $config['url'],
                $config['api_key']
            );
        });

        $this->app->alias(RetailCrmClient::class, 'retailcrm.client');
        
        // Регистрируем сервис RetailCRM как синглтон
        $this->app->singleton(RetailCRMService::class, function ($app) {
            return new RetailCRMService(
                $app[RetailCrmClient::class]
            );
        });

        $this->app->alias(RetailCRMService::class, 'retailcrm.service');

        // Регистрируем OrderConverter с конфигурацией и RetailCRMService
        $this->app->singleton(\App\Services\Converters\OrderConverter::class, function ($app) {
            return new \App\Services\Converters\OrderConverter(
                $app['config']['retailcrm'],
                $app->make(RetailCRMService::class)
            );
        });

        // Регистрируем UserConverter с конфигурацией
        $this->app->singleton(\App\Services\Converters\UserConverter::class, function ($app) {
            return new \App\Services\Converters\UserConverter($app['config']['retailcrm']);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Публикация конфигурационного файла
        $this->publishes([
            __DIR__.'/../../config/retailcrm.php' => config_path('retailcrm.php'),
        ], 'config');
    }
}
