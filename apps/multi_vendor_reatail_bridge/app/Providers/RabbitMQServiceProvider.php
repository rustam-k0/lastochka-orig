<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use App\Services\RabbitMQ\Publisher;
use App\Services\RabbitMQ\Consumer;
use App\Services\RabbitMQ\RabbitMQConnection;

class RabbitMQServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Пропускаем регистрацию если RabbitMQ отключен (например, при тестах)
        if (env('RABBITMQ_ENABLED', true) === false || env('RABBITMQ_ENABLED') === 'false') {
            return;
        }

        // Регистрируем соединение с RabbitMQ
        $this->app->singleton(AMQPStreamConnection::class, function ($app) {
            $config = $app['config']['rabbitmq'];
            
            return new AMQPStreamConnection(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['password'],
                $config['vhost']
            );
        });
        
        // Регистрируем канал RabbitMQ
        $this->app->singleton(AMQPChannel::class, function ($app) {
            return $app->make(AMQPStreamConnection::class)->channel();
        });

        // Обертка соединения для удобства (совместимо с пакетом)
        $this->app->singleton(RabbitMQConnection::class, function ($app) {
            return new RabbitMQConnection($app->make(AMQPStreamConnection::class));
        });
        
        // Регистрируем Publisher
        $this->app->singleton(Publisher::class, function ($app) {
            return new Publisher(
                $app->make(AMQPChannel::class),
                $app['config']['rabbitmq']['publishers']
            );
        });
        
        // Регистрируем Consumer
        $this->app->singleton(Consumer::class, function ($app) {
            return new Consumer(
                $app->make(AMQPChannel::class),
                $app['config']['rabbitmq']['queues']
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Публикация конфигурационного файла
        $this->publishes([
            __DIR__.'/../../config/rabbitmq.php' => config_path('rabbitmq.php'),
        ], 'config');
    }
}
