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
 * Повторная публикация product.updated в event_outbox (backfill).
 *
 * Нужна для устранения НАКОПЛЕННОГО рассинхрона каталожных полей (наименование,
 * штрихкод, категория и т.п.) между 1c_parser и подписчиками (курьерка,
 * storefront), который образовался, пока product.updated вообще не публиковался.
 *
 * Обычная выгрузка 1С такие товары НЕ чинит: в парсере значение уже актуальное,
 * 1С присылает то же самое → updateOrCreate не меняет значимых полей →
 * ProductSyncPublishCondition корректно режет событие. Поэтому застарелый разрыв
 * сам не рассасывается — его нужно догнать принудительной переотправкой.
 *
 * Команда публикует product.updated с актуальным payload (transformer::toEvent)
 * НАПРЯМУЮ в outbox, минуя Observer и condition. event_timestamp = now(), иначе
 * consumer'ы с timestamp-check (storefront) отбросят событие как outdated.
 *
 * Соответствует тому, что делает ModelEventObserver::publishSingleEntity для
 * updated, но без проверки condition (она здесь не нужна — мы осознанно
 * переотправляем).
 */
class ResyncProductsUpdatedCommand extends Command
{
    protected $signature = '1c:resync-products-updated
                            {--ids= : Список Product.id через запятую}
                            {--external-ids= : Список Product.external_id (GUID 1C) через запятую}
                            {--ids-file= : Путь к файлу с external_id (по одному на строку) — для больших списков}
                            {--all : Все товары (батчами по --chunk)}
                            {--limit= : Ограничить количество товаров}
                            {--chunk=500 : Размер чанка}
                            {--dry-run : Не писать в outbox, только посчитать}';

    protected $description = 'Публикует product.updated для товаров (backfill накопленного рассинхрона названий/полей)';

    public function handle(ProductEventTransformer $transformer): int
    {
        $ids = $this->parseList((string) $this->option('ids'));
        $externalIds = $this->parseList((string) $this->option('external-ids'));
        $externalIds = array_merge($externalIds, $this->readIdsFile((string) $this->option('ids-file')));
        $all = (bool) $this->option('all');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        if (!$all && empty($ids) && empty($externalIds)) {
            $this->error('Нужно указать --ids=, --external-ids=, --ids-file= или --all');

            return self::FAILURE;
        }

        $relations = ['category', 'mainImage', 'retailPrice', 'stocks'];

        $query = Product::query()->with($relations);

        if (!$all) {
            $query->where(function ($q) use ($ids, $externalIds) {
                if (!empty($ids)) {
                    $q->whereIn('id', $ids);
                }
                if (!empty($externalIds)) {
                    $q->orWhereIn('external_id', $externalIds);
                }
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        $this->info(sprintf('Товаров к обработке: %d%s', $total, $dryRun ? ' [DRY-RUN]' : ''));

        if ($total === 0) {
            $this->warn('Нет товаров для обработки.');

            return self::SUCCESS;
        }

        $published = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunk, function ($products) use ($transformer, $dryRun, &$published, &$failed, $bar) {
            foreach ($products as $product) {
                try {
                    if (!$dryRun) {
                        $eventId = Str::uuid()->toString();
                        $payload = $transformer->toEvent($product, null, $eventId);
                        $payload['event_type'] = 'updated';
                        $payload['event_source'] = config('rabbitmq-events.source', '1c');
                        $payload['event_timestamp'] = now()->toIso8601String();

                        EventOutbox::create([
                            'entity_type' => 'products',
                            'entity_id' => $product->id,
                            'event_type' => 'updated',
                            'event_id' => $eventId,
                            'routing_key' => 'product.updated',
                            'payload' => $payload,
                        ]);
                    }

                    $published++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::channel('1c-exchange')->error('Failed to resync product.updated', [
                        'product_id' => $product->id,
                        'external_id' => $product->external_id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Всего', $total],
                [$dryRun ? 'Было бы опубликовано' : 'Опубликовано', $published],
                ['Ошибок', $failed],
            ],
        );

        if (!$dryRun && $published > 0) {
            $this->comment('События в event_outbox — worker rabbitmq:publish-outbox опубликует их в RabbitMQ.');
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

    /**
     * @return array<int, string>
     */
    private function readIdsFile(string $path): array
    {
        if ($path === '') {
            return [];
        }

        if (!is_file($path)) {
            $this->warn("Файл не найден: {$path}");

            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_values(array_filter(array_map('trim', $lines)));
    }
}
