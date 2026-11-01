<?php

namespace App\Aggregator;

use App\Aggregator\Console\Commands\RebuildOffersCommand;
use App\Aggregator\Events\OrderPlaced;
use App\Aggregator\Events\ProductImportedFromEvent;
use App\Aggregator\Importers\AggregatorOfferImporter;
use App\Aggregator\Services\OrderAggregatorSplitter;
use App\Aggregator\Support\AggregatorMode;
use App\Aggregator\Transformers\OfferEventTransformer;
use App\Aggregator\Transformers\PlatformProductEventTransformer;
use App\Models\PlatformProduct;
use App\Models\ProductOffer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Точка подключения aggregator-режима (FOODLINK-10874).
 *
 * Регистрируется в config/app.php всегда. Всё, что меняет поведение существующих
 * сценариев (импорт офферов, split заказов по магазинам), включается в boot()
 * только при aggregator_enabled=true. RabbitMQ-сущности офферов регистрируются
 * безусловно — но события по ним рождаются лишь когда импортёр/bootstrap создали
 * офферы (а это возможно только при включённом флаге). См. docs/aggregator-mode.md §3.5.
 */
class AggregatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/aggregator.php', 'aggregator');

        $this->app->singleton(AggregatorMode::class);

        // RabbitMQ-сущности офферов регистрируются безусловно (вызов isEnabled() в
        // register() ненадёжен — БД/кэш могут быть не готовы на этом этапе bootstrap'а).
        // Это fork-safe: ModelEventObserver наблюдает пустые таблицы, события рождаются
        // ТОЛЬКО когда офферы созданы, а офферы создаёт лишь importer (listener за гардом
        // флага в boot()) или команда bootstrap (runtime-гард isEnabled() в handle()).
        // При aggregator_enabled=false офферов нет → событий нет → очередь не отравится.
        $this->registerRabbitMqEntities();
    }

    public function boot(): void
    {
        // Команда регистрируется всегда (в консоли), но её handle() сам проверяет
        // isEnabled() в рантайме (§11.1: bootstrap запускается ПОСЛЕ включения режима).
        // Безопасно: публикацию offer-событий гейтит регистрация RabbitMQ-сущностей
        // в register() — при выключённом флаге observer'ы не навешаны, очередь не отравится.
        if ($this->app->runningInConsole()) {
            $this->commands([RebuildOffersCommand::class]);
        }

        if (!$this->app->make(AggregatorMode::class)->isEnabled()) {
            return;
        }

        // Импорт офферов: навешивается ПОСЛЕ стандартного ProductEventTransformer
        // (не вместо). В форках без флага listener не зарегистрирован → событие no-op.
        Event::listen(ProductImportedFromEvent::class, AggregatorOfferImporter::class);

        // Split заказа по магазинам-поставщикам. Событие OrderPlaced бросается
        // безусловно из OrderEventTransformer; сплиттер активируется только здесь.
        Event::listen(OrderPlaced::class, OrderAggregatorSplitter::class);
    }

    /**
     * Зарегистрировать офферы и платформенные карточки как RabbitMQ-сущности
     * (публикация через стандартный ModelEventObserver по Eloquent created/updated/deleted).
     *
     * Делается в register() — до того как RabbitMQEventsServiceProvider::boot()
     * прочитает config('rabbitmq-events.entities') и навесит observer'ы.
     */
    private function registerRabbitMqEntities(): void
    {
        $entities = config('rabbitmq-events.entities', []);

        $entities['product_offers'] = [
            'enabled' => true,
            'model' => ProductOffer::class,
            'transformer' => OfferEventTransformer::class,
            'events' => ['created', 'updated', 'deleted'],
            'relations' => ['platformProduct.barcodes', 'shop', 'localProduct'],
            'routing_key_prefix' => config('aggregator.routing.offer_prefix', 'aggregator.offer'),
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'aggregator.offer.created',
                'aggregator.offer.updated',
                'aggregator.offer.deleted',
            ],
        ];

        $entities['platform_products'] = [
            'enabled' => true,
            'model' => PlatformProduct::class,
            'transformer' => PlatformProductEventTransformer::class,
            'events' => ['created', 'updated', 'deleted'],
            'relations' => ['barcodes', 'offers'],
            'routing_key_prefix' => config('aggregator.routing.platform_product_prefix', 'aggregator.platform_product'),
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'aggregator.platform_product.created',
                'aggregator.platform_product.updated',
                'aggregator.platform_product.deleted',
            ],
        ];

        config(['rabbitmq-events.entities' => $entities]);
    }
}
