<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Команда для первичной публикации всех категорий и товаров в RabbitMQ.
 *
 * Используется при первоначальной синхронизации данных с внешними системами.
 * Создаёт события в event_outbox, которые затем публикуются outbox worker'ом.
 */
class PublishAllToRabbitMQ extends Command
{
    protected $signature = 'rabbitmq:publish-all
                            {--categories : Публиковать только категории}
                            {--products : Публиковать только товары}
                            {--chunk=500 : Размер пакета для обработки}
                            {--dry-run : Показать что будет опубликовано без записи}';

    protected $description = 'Публикует все существующие категории и товары в RabbitMQ для первичной синхронизации';

    protected int $categoriesCount = 0;
    protected int $productsCount = 0;

    public function handle(): int
    {
        $publishCategories = $this->option('categories') || !$this->option('products');
        $publishProducts = $this->option('products') || !$this->option('categories');
        $chunkSize = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN - события НЕ будут записаны в outbox');
        }

        $this->info('Начинаю первичную публикацию в RabbitMQ...');

        if ($publishCategories) {
            $this->publishCategories($chunkSize, $dryRun);
        }

        if ($publishProducts) {
            $this->publishProducts($chunkSize, $dryRun);
        }

        $this->newLine();
        $this->info('✅ Публикация завершена:');
        $this->table(
            ['Тип', 'Количество'],
            [
                ['Категории', $this->categoriesCount],
                ['Товары', $this->productsCount],
                ['Всего', $this->categoriesCount + $this->productsCount],
            ]
        );

        if (!$dryRun) {
            $pending = EventOutbox::unpublished()->count();
            $this->info("📤 В очереди на отправку: {$pending} событий");
            $this->comment('Запустите outbox worker для публикации: php artisan rabbitmq:publish-outbox');
        }

        return self::SUCCESS;
    }

    /**
     * Публикация всех категорий
     */
    protected function publishCategories(int $chunkSize, bool $dryRun): void
    {
        $total = Category::count();
        $this->info("📁 Публикация категорий: {$total} шт.");

        if ($total === 0) {
            $this->warn('  Категории не найдены');
            return;
        }

        $transformer = app(config('rabbitmq-events.entities.categories.transformer'));
        $routingKeyPrefix = config('rabbitmq-events.entities.categories.routing_key_prefix', 'category_1c');
        $relations = config('rabbitmq-events.entities.categories.relations', []);

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Category::with($relations)
            ->orderBy('id')
            ->chunk($chunkSize, function ($categories) use ($transformer, $routingKeyPrefix, $dryRun, $bar) {
                foreach ($categories as $category) {
                    if (!$dryRun) {
                        $this->createOutboxEvent(
                            entityType: 'categories',
                            entityId: $category->id,
                            eventType: 'created',
                            routingKey: "{$routingKeyPrefix}.created",
                            payload: $transformer->toEvent($category)
                        );
                    }

                    $this->categoriesCount++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
    }

    /**
     * Публикация всех товаров
     */
    protected function publishProducts(int $chunkSize, bool $dryRun): void
    {
        $total = Product::count();
        $this->info("📦 Публикация товаров: {$total} шт.");

        if ($total === 0) {
            $this->warn('  Товары не найдены');
            return;
        }

        $transformer = app(config('rabbitmq-events.entities.products.transformer'));
        $routingKeyPrefix = config('rabbitmq-events.entities.products.routing_key_prefix', 'product');
        $relations = config('rabbitmq-events.entities.products.relations', []);

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Product::with($relations)
            ->orderBy('id')
            ->chunk($chunkSize, function ($products) use ($transformer, $routingKeyPrefix, $dryRun, $bar) {
                foreach ($products as $product) {
                    if (!$dryRun) {
                        $this->createOutboxEvent(
                            entityType: 'products',
                            entityId: $product->id,
                            eventType: 'created',
                            routingKey: "{$routingKeyPrefix}.created",
                            payload: $transformer->toEvent($product)
                        );
                    }

                    $this->productsCount++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
    }

    /**
     * Создание записи в event_outbox
     */
    protected function createOutboxEvent(
        string $entityType,
        int $entityId,
        string $eventType,
        string $routingKey,
        array $payload
    ): void {
        $eventId = $payload['event_id'] ?? Str::uuid()->toString();
        $source = config('rabbitmq-events.source', '1c');

        // Добавляем обязательные поля
        $payload['event_id'] = $eventId;
        $payload['event_type'] = $eventType;
        $payload['event_source'] = $source;

        EventOutbox::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_type' => $eventType,
            'event_id' => $eventId,
            'routing_key' => $routingKey,
            'payload' => $payload,
        ]);
    }
}
