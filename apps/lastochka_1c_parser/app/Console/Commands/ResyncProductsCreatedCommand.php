<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Повторная публикация product.created в event_outbox.
 *
 * Когда admin_back по каким-то причинам не обработал исходный product.created
 * (смена shop_code в парсере, downtime консьюмера, purge очереди) — товар
 * залипает в состоянии, когда его event_sync_metadata.entity_id = NULL, а
 * custom-события (price_updated/stock_updated) скипаются консьюмером с warning
 * "entity must be created via standard event first".
 *
 * Команда даёт ручной хук дергать product.created заново для выбранных
 * товаров, используя актуальный payload из transformer::toEvent. Соответствует
 * тому, что делает ModelEventObserver::publishSingleEntity (см. обсервер).
 *
 * Перед запуском — удалить соответствующие stub-записи event_sync_metadata
 * в admin_back (чтобы консьюмер создал новую metadata + новый Product).
 */
class ResyncProductsCreatedCommand extends Command
{
    protected $signature = '1c:resync-products-created
                            {--ids= : Список Product.id через запятую}
                            {--external-ids= : Список Product.external_id (GUID 1C) через запятую}
                            {--dry-run : Не писать в outbox, только показать что бы сделали}';

    protected $description = 'Публикует product.created события для указанных товаров (повторная синхронизация)';

    public function handle(ProductEventTransformer $transformer): int
    {
        $ids = $this->parseList((string) $this->option('ids'));
        $externalIds = $this->parseList((string) $this->option('external-ids'));
        $dryRun = (bool) $this->option('dry-run');

        if (empty($ids) && empty($externalIds)) {
            $this->error('Нужно указать --ids= или --external-ids=');

            return self::FAILURE;
        }

        $relations = ['category', 'mainImage', 'retailPrice', 'stocks'];

        $products = Product::query()
            ->with($relations)
            ->where(function ($query) use ($ids, $externalIds) {
                if (!empty($ids)) {
                    $query->whereIn('id', $ids);
                }
                if (!empty($externalIds)) {
                    $query->orWhereIn('external_id', $externalIds);
                }
            })
            ->get();

        $this->info(sprintf(
            'Найдено товаров: %d%s',
            $products->count(),
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        if ($products->isEmpty()) {
            $this->warn('Нет товаров для обработки.');

            return self::SUCCESS;
        }

        $published = 0;
        $failed = 0;
        $rows = [];

        foreach ($products as $product) {
            try {
                $eventId = Str::uuid()->toString();
                $payload = $transformer->toEvent($product, null, $eventId);
                // toEvent кладёт active только при wasRecentlyCreated (живой
                // created-observer); здесь модель загружена из БД, но событие
                // семантически created — начальный статус консьюмерам обязателен
                // (у admin_back products.status_id NOT NULL без default).
                $payload['active'] = (bool) $product->is_active;
                $payload['event_type'] = 'created';
                $payload['event_source'] = config('rabbitmq-events.source', '1c');
                // При resync важно проставить свежий event_timestamp: иначе
                // consumer'ы с timestamp-check (например, storefront)
                // проигнорируют событие как outdated, если у них last_event_timestamp
                // metadata новее, чем $product->updated_at.
                $payload['event_timestamp'] = now()->toIso8601String();

                $rows[] = [
                    $product->id,
                    $product->external_id,
                    $product->sku ?: 'NULL',
                    $payload['shop_code'] ?? 'NULL',
                    mb_substr((string) $product->name, 0, 50),
                ];

                if ($dryRun) {
                    $published++;
                    continue;
                }

                EventOutbox::create([
                    'entity_type' => 'products',
                    'entity_id' => $product->id,
                    'event_type' => 'created',
                    'event_id' => $eventId,
                    'routing_key' => 'product.created',
                    'payload' => $payload,
                ]);

                $published++;
            } catch (Throwable $e) {
                $failed++;
                Log::channel('1c-exchange')->error('Failed to resync product.created', [
                    'product_id' => $product->id,
                    'external_id' => $product->external_id,
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf(
                    'Ошибка для товара id=%d external_id=%s: %s',
                    $product->id,
                    $product->external_id,
                    $e->getMessage(),
                ));
            }
        }

        $this->newLine();
        $this->table(
            ['id', 'external_id', 'sku', 'shop_code', 'title'],
            $rows,
        );

        $this->newLine();
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Всего', $products->count()],
                [$dryRun ? 'Было бы опубликовано' : 'Опубликовано', $published],
                ['Ошибок', $failed],
            ],
        );

        if (!$dryRun && $published > 0) {
            $this->comment('События лежат в event_outbox — worker rabbitmq:publish-outbox опубликует их в RabbitMQ.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function parseList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
