<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\IntegrationConfig;
use App\RabbitMQ\Transformers\CategoryEventTransformer;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Повторная публикация category.created в event_outbox.
 *
 * Используется для первого запуска обмена категориями (после включения фича-флага
 * RABBITMQ_SYNC_CATEGORIES=true) или для ручного бэкфилла, когда admin_back
 * по какой-то причине не обработал часть событий (смена shop_code в парсере,
 * downtime консьюмера, purge очереди).
 *
 * Категории публикуются в порядке «корни → потомки», чтобы consumer на стороне
 * admin_back успел создать event_sync_metadata для родителя до того, как
 * приедет потомок со ссылкой parent_id → parent_external_id.
 *
 * Фильтрация по shop_id: команда требует --shop=<code>, резолв через
 * IntegrationConfig (integration_type='1c', shop_code=...). Legacy-дубликаты
 * с другим shop_id (эхо миграции add_shop_id_to_categories) игнорируются.
 */
class ResyncCategoriesCommand extends Command
{
    protected $signature = '1c:resync-categories
                            {--shop= : Shop code (как в integration_configs.shop_code, например djami_store)}
                            {--dry-run : Не писать в outbox, только показать что бы сделали}
                            {--only-roots : Опубликовать только корневые категории}';

    protected $description = 'Публикует category.created события для всех категорий указанного магазина (повторная синхронизация)';

    public function handle(CategoryEventTransformer $transformer): int
    {
        $shopCode = (string) $this->option('shop');
        $dryRun = (bool) $this->option('dry-run');
        $onlyRoots = (bool) $this->option('only-roots');

        if ($shopCode === '') {
            $this->error('Нужно указать --shop=<code>, например --shop=djami_store');

            return self::FAILURE;
        }

        $config = IntegrationConfig::where('integration_type', '1c')
            ->where('shop_code', $shopCode)
            ->first();

        if ($config === null) {
            $this->error(sprintf('Магазин не найден в integration_configs: shop_code=%s, integration_type=1c', $shopCode));

            return self::FAILURE;
        }

        $shopId = (int) $config->shop_id;

        app()->instance('current_shop_id', $shopId);
        app()->instance('current_shop_code', $shopCode);

        $query = Category::query()
            ->with(['parent'])
            ->where('shop_id', $shopId)
            ->orderByRaw('parent_id IS NULL DESC')
            ->orderBy('parent_id')
            ->orderBy('id');

        if ($onlyRoots) {
            $query->whereNull('parent_id');
        }

        $categories = $query->get();

        $this->info(sprintf(
            'Найдено категорий: %d (shop_code=%s, shop_id=%d)%s',
            $categories->count(),
            $shopCode,
            $shopId,
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        if ($categories->isEmpty()) {
            $this->warn('Нет категорий для публикации.');

            return self::SUCCESS;
        }

        $published = 0;
        $failed = 0;

        $routingKeyPrefix = (string) config('rabbitmq-events.entities.categories.routing_key_prefix', 'category_1c');
        $routingKey = $routingKeyPrefix . '.created';

        foreach ($categories as $category) {
            try {
                $eventId = Str::uuid()->toString();
                $payload = $transformer->toEvent($category, null, $eventId);
                $payload['event_type'] = 'created';
                $payload['event_source'] = config('rabbitmq-events.source', '1c');
                $payload['event_timestamp'] = now()->toIso8601String();

                if ($dryRun) {
                    $this->line(sprintf(
                        '  [dry-run] %s (external_id=%s, parent=%s)',
                        $category->name,
                        $category->external_id,
                        $category->parent?->external_id ?? 'root',
                    ));
                    $published++;
                    continue;
                }

                EventOutbox::create([
                    'entity_type' => 'categories',
                    'entity_id' => $category->id,
                    'event_type' => 'created',
                    'event_id' => $eventId,
                    'routing_key' => $routingKey,
                    'payload' => $payload,
                ]);

                $published++;
            } catch (Throwable $e) {
                $failed++;
                Log::channel('1c-exchange')->error('Failed to resync category.created', [
                    'category_id' => $category->id,
                    'external_id' => $category->external_id,
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf(
                    'Ошибка для category.id=%d: %s',
                    $category->id,
                    $e->getMessage(),
                ));
            }
        }

        $this->info(sprintf('Опубликовано: %d, ошибок: %d', $published, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
