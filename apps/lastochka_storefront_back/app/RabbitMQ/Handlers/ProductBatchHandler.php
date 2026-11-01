<?php

declare(strict_types=1);

namespace App\RabbitMQ\Handlers;

use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Store;
use App\Models\StoreProduct;
use App\RabbitMQ\Support\NetworkCatalog;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use App\Services\Pricing\PriceTierLadder;
use App\Services\Pricing\ProductTierLadderProvider;
use App\Services\Search\SearchService;
use App\Services\Settings\ModuleConfigService;
use Carbon\Carbon;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Обработчик батч-событий товаров от 1С (контракт
 * docs/specs/2026-06-24-1c-rabbitmq-direct-contract.md, раздел 6).
 *
 * Семантика: батч эквивалентен N одиночным событиям с общим конвертом
 * (shop_code/event_id/event_timestamp одни на сообщение). Одна битая
 * позиция не роняет батч (skip + счётчик), сводный лог — одной строкой
 * на сообщение. Возвращает 'ack' всегда, кроме инфраструктурного сбоя
 * всего сообщения (исключение наружу → retry пакета ×3 → DLQ).
 *
 * Идемпотентность per-item: повтор event_id конверта и устаревший
 * event_timestamp скипаются. Это обязательный слой: retry пакета
 * репаблишит сообщение в exchange по тому же routing key, и оно
 * доставляется в батч-очереди ОБОИХ потребителей повторно.
 */
final class ProductBatchHandler
{
    public function __construct(
        private readonly ProductEventTransformer $transformer,
        private readonly SearchService $searchService,
    ) {}

    /**
     * @param array<string, mixed> $eventData
     */
    public function handle(array $eventData): string
    {
        return match ($eventData['event_type'] ?? '') {
            'price_batch_updated' => $this->processPriceBatch($eventData),
            'stock_batch_updated' => $this->processStockBatch($eventData),
            'batch_updated' => $this->processProductBatch($eventData),
            default => 'ack', // неизвестный тип не блокирует очередь
        };
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function processPriceBatch(array $eventData): string
    {
        [$resolved, $stats] = $this->resolveItems($eventData);

        $changed = new EloquentCollection();
        $bumpIds = [];

        $networkMode = NetworkCatalog::enabled();
        $storeRows = $this->storeProductRowsForDiff($networkMode, $eventData, $resolved);

        // Bulk-путь: в сетевом режиме простые позиции (без карточных полей
        // кванта) пишутся массово в store_products, минуя per-item трансформер.
        // Shadow: пишем per-item, но считаем bulk-строку и сверяем с фактом.
        $bulkOn = (bool) config('rabbitmq-events.bulk_price_batch') && $networkMode;
        $shadowOn = $networkMode && ! $bulkOn && (bool) config('rabbitmq-events.bulk_price_batch_shadow');
        $storeId = ($bulkOn || $shadowOn) ? $this->resolveBatchStoreId($eventData) : null;
        $bulkRows = [];
        $shadowRows = [];

        // MID-308, цены от количества: лесенки магазина подгружаются одним
        // запросом рядом с ценами, изменённые пишутся пачкой после upsert цен.
        // Запись ступеней батч берёт на себя и для bulk-, и для поштучных позиций.
        $tiersOn = app(ModuleConfigService::class)->isPriceTiersEnabledForConsumer();
        $tierStoreId = ($tiersOn && $networkMode) ? ($storeId ?? $this->resolveBatchStoreId($eventData)) : null;
        // Финальное ревью (F5): existingLadders() выполнялся на каждом батче
        // цен, даже если ни одна позиция пачки не несёт ключ price_tiers —
        // лишний SELECT. Пропускаем его, когда ключа нет ни у одной позиции.
        $tiersPresent = $tiersOn && array_any($resolved, static fn (array $row): bool => array_key_exists('price_tiers', $row['item']));
        $existingLadders = $tiersPresent ? $this->existingLadders($resolved, $networkMode, $tierStoreId) : [];
        $tierWrites = [];

        // Observer подавлен: Meili индексируется одной пачкой ниже.
        Product::withoutEvents(function () use ($resolved, $eventData, &$stats, &$bumpIds, $changed, $networkMode, $storeRows, $bulkOn, $shadowOn, $storeId, &$bulkRows, &$shadowRows, $tiersOn, $tierStoreId, $existingLadders, &$tierWrites): void {
            foreach ($resolved as $row) {
                /** @var Product $product */
                $product = $row['product'];
                $item = $row['item'];

                try {
                    if (! isset($item['price'])) {
                        $stats['errors']++;
                        continue;
                    }

                    // MID-308: новая лесенка позиции либо null (не трогаем). Ключ
                    // убираем: до поштучного трансформера он доезжать не должен.
                    $newLadder = $tiersOn
                        ? $this->changedLadder($item, $product, $networkMode, $tierStoreId, $existingLadders, $eventData, $stats)
                        : null;

                    // Финальное ревью (F14): видимость риска «ступень дороже
                    // обычной цены без скидки» — без лишних запросов на позицию.
                    if ($newLadder !== null) {
                        $this->warnIfTierAboveRegularPriceInBatch($product, $newLadder, $item, $networkMode, $tierStoreId);
                    }

                    unset($item['price_tiers']);

                    if ($this->priceUnchanged($product, $item, $networkMode, $storeRows->get($product->id), $newLadder !== null)) {
                        $stats['unchanged']++;
                        $bumpIds[] = $row['metadata']->id;
                        continue;
                    }

                    // Простая пер-магазинная цена без карточных полей кванта:
                    // пишем только store_products (в документе Meili её нет),
                    // поэтому bulk и НЕ пушим в $changed.
                    $eligible = $storeId !== null && ! $this->carriesQuantumCardFields($item);

                    if ($bulkOn && $eligible) {
                        $bulkRows[] = $this->buildStorePriceRow($storeId, $product->id, $item);
                        $this->queueLadderWrite($tierWrites, $newLadder, $product, $networkMode, $tierStoreId);
                        $stats['processed']++;
                        $bumpIds[] = $row['metadata']->id;
                        continue;
                    }

                    if ($shadowOn && $eligible) {
                        $shadowRows[$product->id] = $this->buildStorePriceRow($storeId, $product->id, $item);
                    }

                    $this->transformer->processPriceUpdatedEvent(
                        $product,
                        $this->itemPayload($item, $eventData)
                    );
                    $this->queueLadderWrite($tierWrites, $newLadder, $product, $networkMode, $tierStoreId);

                    // Сетевой режим: цена товара пер-магазинная (store_products),
                    // в документе Meili её нет — переиндексировать нечего.
                    // Исключение — позиции с карточными полями кванта: шаг продажи
                    // глобален и участвует в in_stock документа.
                    if (! $networkMode || $this->carriesQuantumCardFields($item)) {
                        $changed->push($product);
                    }

                    $stats['processed']++;
                    $bumpIds[] = $row['metadata']->id;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->logItemError('price_batch_updated', $eventData, $item, $e);
                }
            }
        });

        foreach (array_chunk($bulkRows, 500) as $chunk) {
            StoreProduct::upsert($chunk, ['store_id', 'product_id'], ['price', 'price_old', 'synced_at', 'updated_at']);
        }

        // MID-308: изменённые лесенки пачкой, после upsert цен: DELETE по
        // (магазин, товары чанка) плюс один массовый INSERT.
        foreach ($tierWrites as $ladderStoreId => $ladders) {
            ProductPriceTier::replaceLadders((int) $ladderStoreId, $ladders);
        }
        if ($tierWrites !== []) {
            app(ProductTierLadderProvider::class)->flush();
        }

        if ($shadowRows !== []) {
            $this->logStoreShadowCompare('price_batch', $shadowRows, (int) $storeId, $eventData, ['price', 'price_old']);
        }

        $this->searchService->indexProducts($changed);
        $this->bumpMetadata($bumpIds, $eventData);
        $this->logSummary('price_batch_updated', $eventData, $stats);

        return 'ack';
    }

    /**
     * Быстрый no-op: цена уже совпадает. Зеркалит семантику
     * processPriceUpdatedEvent (инверсия discount_price → price/price_old,
     * округление до копеек). measurement_unit/quantity_step требуют полного
     * пути — они меняют не только price.
     *
     * База сравнения зависит от режима: легаси — колонки products, сетевой —
     * строка store_products магазина конверта (в products цены магазина нет,
     * сравнение с ней отсекало бы позиции, которых в store_products ещё нет).
     *
     * @param array<string, mixed> $item
     */
    private function priceUnchanged(Product $product, array $item, bool $networkMode, ?StoreProduct $storeRow, bool $ladderChanged = false): bool
    {
        // MID-308: изменилась лесенка цен от количества: позиция не no-op,
        // даже если цена совпала. Совпавшая лесенка сюда приходит как false,
        // и позиция по-прежнему пропускается.
        if ($ladderChanged) {
            return false;
        }

        // Карточные поля кванта применяются и в сетевом режиме
        // (quantumCardFieldsFromPriceEvent) — diff их отсекать не должен,
        // иначе на неизменившейся цене шаг продажи/единица потеряются.
        if ($this->carriesQuantumCardFields($item)) {
            return false;
        }

        $basePrice = round((float) $item['price'], 2);
        $discountPrice = isset($item['discount_price'])
            ? round((float) $item['discount_price'], 2)
            : null;

        $expectedPrice = $discountPrice ?? $basePrice;
        $expectedOld = $discountPrice ? $basePrice : null;

        if ($networkMode) {
            // Строки магазина ещё нет — это первая цена, позицию обрабатываем.
            if ($storeRow === null) {
                return false;
            }

            $currentPrice = (float) $storeRow->price;
            $currentOld = $storeRow->price_old;
        } else {
            $currentPrice = (float) $product->price;
            $currentOld = $product->price_old;
        }

        if (abs($currentPrice - $expectedPrice) >= 0.001) {
            return false;
        }

        return $expectedOld === null
            ? $currentOld === null
            : $currentOld !== null && abs((float) $currentOld - $expectedOld) < 0.001;
    }

    /**
     * Позиция несёт карточные (глобальные) поля кванта — шаг продажи и/или
     * единицу измерения. Такие позиции идут полным путём в обоих режимах.
     *
     * @param array<string, mixed> $item
     */
    private function carriesQuantumCardFields(array $item): bool
    {
        return isset($item['quantity_step']) || isset($item['measurement_unit']);
    }

    /**
     * MID-308: сохранённые лесенки товаров батча одним запросом.
     * Магазин: в сетевом режиме магазин конверта, в обычном магазин товара.
     *
     * @param  list<array{item: array<string, mixed>, metadata: EventSyncMetadata, product: Product}>  $resolved
     * @return array<string, array<int, array{min_quantity: float, price: float}>>  ключ «store_id:product_id»
     */
    private function existingLadders(array $resolved, bool $networkMode, ?int $tierStoreId): array
    {
        if ($resolved === []) {
            return [];
        }

        $productIds = array_map(static fn (array $row): int => (int) $row['product']->id, $resolved);

        $storeIds = $networkMode
            ? ($tierStoreId !== null ? [$tierStoreId] : [])
            : array_values(array_unique(array_map(static fn (array $row): int => (int) $row['product']->store_id, $resolved)));

        return ProductPriceTier::laddersFor($storeIds, $productIds);
    }

    /**
     * MID-308: лесенка позиции, которую нужно записать ([] = удалить), либо
     * null, когда её не трогаем: ключа нет, магазин не определён (в сетевом
     * режиме без shop_code, как и цена), лесенка битая (предупреждение в лог,
     * цена позиции применяется) либо совпала с сохранённой.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, array<int, array{min_quantity: float, price: float}>>  $existingLadders
     * @param  array<string, mixed>  $eventData
     * @param  array<string, int>  $stats  финальное ревью F4: инкремент
     *                                      'tiers_dropped' при отбрасывании
     *                                      битой лесенки позиции
     * @return array<int, array{min_quantity: float, price: float}>|null
     */
    private function changedLadder(array $item, Product $product, bool $networkMode, ?int $tierStoreId, array $existingLadders, array $eventData, array &$stats): ?array
    {
        if (! array_key_exists('price_tiers', $item)) {
            return null;
        }

        $ladderStoreId = $networkMode ? $tierStoreId : (int) $product->store_id;

        if ($ladderStoreId === null || $ladderStoreId <= 0) {
            return null;
        }

        $ladder = PriceTierLadder::normalize($item['price_tiers']);

        if ($ladder === null) {
            $stats['tiers_dropped']++;

            Log::warning('Broken price_tiers ladder dropped in batch, price applied', [
                'event_id' => $eventData['event_id'] ?? null,
                'shop_code' => $eventData['shop_code'] ?? null,
                'item_id' => $item['id'] ?? null,
                'product_id' => $product->id,
            ]);

            return null;
        }

        $existing = $existingLadders[$ladderStoreId.':'.$product->id] ?? [];

        return PriceTierLadder::same($ladder, $existing) ? null : $ladder;
    }

    /**
     * MID-308: поставить лесенку в очередь пачечной записи. Вызывается только
     * после успешной обработки цены позиции: упавшая позиция лесенку не пишет.
     *
     * @param  array<int, array<int, array<int, array{min_quantity: float, price: float}>>>  $tierWrites  store_id => product_id => лесенка
     * @param  array<int, array{min_quantity: float, price: float}>|null  $ladder
     */
    private function queueLadderWrite(array &$tierWrites, ?array $ladder, Product $product, bool $networkMode, ?int $tierStoreId): void
    {
        if ($ladder === null) {
            return;
        }

        $ladderStoreId = $networkMode ? (int) $tierStoreId : (int) $product->store_id;

        $tierWrites[$ladderStoreId][(int) $product->id] = $ladder;
    }

    /**
     * MID-308 (финальное ревью F14): видимость риска «ступень дороже обычной
     * цены без скидки» (правило «ступень приоритетнее акции» плюс
     * немонотонная лесенка от 1С, которую мы не отклоняем). Без лишних
     * запросов на позицию: сравниваем с сырой ценой ЭТОГО события — она
     * равна цене за порцию только у товара без кванта (quantity_step <= 1).
     * У квантового товара сырая цена события ещё не приведена к цене кванта
     * без reconcileQuantumUnits(), а лесенка — за базовую единицу: конвертация
     * не бесплатна, такие позиции сознательно пропускаем (разрешено ревью).
     *
     * @param  array<int, array{min_quantity: float, price: float}>  $ladder  только ИЗМЕНЁННАЯ лесенка (changedLadder()), базовые единицы
     * @param  array<string, mixed>  $item
     */
    private function warnIfTierAboveRegularPriceInBatch(Product $product, array $ladder, array $item, bool $networkMode, ?int $tierStoreId): void
    {
        if ($ladder === [] || (int) ($product->quantity_step ?? 1) > 1) {
            return;
        }

        $regularPrice = round((float) ($item['price'] ?? 0), 2);

        if ($regularPrice <= 0) {
            return;
        }

        foreach ($ladder as $tier) {
            if ($tier['price'] > $regularPrice + 0.0005) {
                Log::warning('price_tiers ladder has a tier above regular price', [
                    'product_id' => $product->id,
                    'store_id' => $networkMode ? $tierStoreId : (int) $product->store_id,
                    'regular_price' => $regularPrice,
                    'tier' => $tier,
                ]);

                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function processStockBatch(array $eventData): string
    {
        [$resolved, $stats] = $this->resolveItems($eventData);

        $changed = new EloquentCollection();
        $bumpIds = [];

        $networkMode = NetworkCatalog::enabled();
        $storeRows = $this->storeProductRowsForDiff($networkMode, $eventData, $resolved);

        $bulkOn = (bool) config('rabbitmq-events.bulk_price_batch') && $networkMode;
        $shadowOn = $networkMode && ! $bulkOn && (bool) config('rabbitmq-events.bulk_price_batch_shadow');
        $storeId = ($bulkOn || $shadowOn) ? $this->resolveBatchStoreId($eventData) : null;
        $bulkRows = [];
        $shadowRows = [];

        Product::withoutEvents(function () use ($resolved, $eventData, &$stats, &$bumpIds, $changed, $networkMode, $storeRows, $bulkOn, $shadowOn, $storeId, &$bulkRows, &$shadowRows): void {
            foreach ($resolved as $row) {
                /** @var Product $product */
                $product = $row['product'];
                $item = $row['item'];

                try {
                    if (! isset($item['quantity'])) {
                        $stats['errors']++;
                        continue;
                    }

                    if ($this->stockUnchanged($product, $item, $networkMode, $storeRows->get($product->id))) {
                        $stats['unchanged']++;
                        $bumpIds[] = $row['metadata']->id;
                        continue;
                    }

                    // Сетевой остаток пишется только в store_products и не идёт в
                    // Meili — bulk-путь. (stock_mode ignore/fixed уже отсеян
                    // stockUnchanged выше.)
                    if ($bulkOn && $storeId !== null) {
                        $bulkRows[] = $this->buildStoreStockRow($storeId, $product, $item);
                        $stats['processed']++;
                        $bumpIds[] = $row['metadata']->id;
                        continue;
                    }

                    if ($shadowOn && $storeId !== null) {
                        $shadowRows[$product->id] = $this->buildStoreStockRow($storeId, $product, $item);
                    }

                    $this->transformer->processStockUpdatedEvent(
                        $product,
                        $this->itemPayload($item, $eventData)
                    );

                    // Сетевой режим: остаток пер-магазинный (store_products),
                    // глобальный документ Meili от него не меняется.
                    if (! $networkMode) {
                        $changed->push($product);
                    }

                    $stats['processed']++;
                    $bumpIds[] = $row['metadata']->id;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->logItemError('stock_batch_updated', $eventData, $item, $e);
                }
            }
        });

        foreach (array_chunk($bulkRows, 500) as $chunk) {
            StoreProduct::upsert($chunk, ['store_id', 'product_id'], ['quantity', 'synced_at', 'updated_at']);
        }

        if ($shadowRows !== []) {
            $this->logStoreShadowCompare('stock_batch', $shadowRows, (int) $storeId, $eventData, ['quantity']);
        }

        $this->searchService->indexProducts($changed);
        $this->bumpMetadata($bumpIds, $eventData);
        $this->logSummary('stock_batch_updated', $eventData, $stats);

        return 'ack';
    }

    /**
     * Полные карточки: каждая позиция идёт через штатный пайплайн пакета
     * (metadata INSERT-first, findExistingEntity, категории/pending refs,
     * conditional set). Выигрыш батча — транспорт И индексация: Meili-вызовы
     * из afterCreate/afterUpdate откладываются defer-режимом трансформера и
     * выполняются одним bulk-вызовом после цикла (per-item HTTP-индексация
     * на полной выгрузке 1С делала Meili узким местом всего сервера).
     *
     * Один event_id на все позиции: дубль товара внутри батча отсекает
     * uniqueItems() ещё до вызова пайплайна («первая позиция выигрывает»);
     * повторная доставка сообщения (тот же event_id) — полный no-op
     * благодаря idempotency-guard'у самого пайплайна (per-entity
     * last_event_id в EventSyncMetadata).
     *
     * @param array<string, mixed> $eventData
     */
    private function processProductBatch(array $eventData): string
    {
        $stats = $this->emptyStats();
        $items = $this->uniqueItems($eventData, $stats);

        $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));

        ProductEventTransformer::beginDeferredReindex();

        try {
            foreach ($items as $item) {
                try {
                    $consumer->processEvent($this->itemPayload($item, $eventData, 'updated'));
                    $stats['processed']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->logItemError('batch_updated', $eventData, $item, $e);
                }
            }
        } finally {
            $this->flushDeferredReindex();
        }

        $this->logSummary('batch_updated', $eventData, $stats);

        return 'ack';
    }

    /**
     * Однократная bulk-индексация товаров, накопленных за батч в defer-режиме.
     *
     * Сбой Meili не роняет сообщение: товары уже в БД и обработаны, батч
     * ack'ается, индекс догонит следующим событием или search:reindex.
     */
    private function flushDeferredReindex(): void
    {
        $ids = ProductEventTransformer::endDeferredReindex();

        if ($ids === []) {
            return;
        }

        try {
            $products = Product::withTrashed()->whereIn('id', $ids)->get();

            [$trashed, $alive] = $products->partition(
                static fn (Product $p) => $p->trashed()
            );

            foreach ($trashed as $product) {
                $this->searchService->removeProduct($product->id);
            }

            // indexProducts сам разводит неактивные/child-товары в deleteDocuments.
            $this->searchService->indexProducts($alive->values());
        } catch (\Throwable $e) {
            Log::error('Deferred batch reindex failed', [
                'ids_count' => count($ids),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Быстрый no-op остатка. ignore/fixed: остатком управляет сайт,
     * processStockUpdatedEvent всё равно вернулся бы без изменений
     * (в обоих режимах — upsertStoreProductStock тоже их пропускает).
     *
     * База сравнения зависит от режима: легаси — products.quantity,
     * сетевой — store_products.quantity магазина конверта.
     *
     * @param array<string, mixed> $item
     */
    private function stockUnchanged(Product $product, array $item, bool $networkMode, ?StoreProduct $storeRow): bool
    {
        if (in_array($product->stock_mode, ['ignore', 'fixed'], true)) {
            return true;
        }

        $expected = $this->transformer->toStoredQuantity($item['quantity'] ?? 0, $product->unit_type?->value);

        if ($networkMode) {
            // Строки магазина ещё нет — это первый остаток, позицию обрабатываем.
            return $storeRow !== null && (int) $storeRow->quantity === $expected;
        }

        return (int) $product->quantity === $expected;
    }

    /**
     * Сетевой режим: текущие строки store_products магазина конверта одним
     * SELECT на батч — база diff-оптимизации цен/остатков.
     *
     * Магазин конверта не резолвится (пустой/дефолтный/неизвестный shop_code) —
     * пустая коллекция: diff пропускается, позиции идут полным путём, где их
     * штатно дропает трансформер с логом, как одиночное событие.
     *
     * @param array<string, mixed> $eventData
     * @param list<array{item: array<string, mixed>, metadata: EventSyncMetadata, product: Product}> $resolved
     * @return Collection<int, StoreProduct>
     */
    /**
     * store_id магазина конверта (сетевой режим). Повторяет резолв из
     * storeProductRowsForDiff / resolveStoreForPerShopEvent: непустой не-default
     * shop_code -> shops.code. null -> bulk-путь отключён, идёт per-item.
     */
    private function resolveBatchStoreId(array $eventData): ?int
    {
        $shopCode = (string) ($eventData['shop_code'] ?? '');
        $defaultShopCode = (string) config('rabbitmq-events.shop_code', 'default');

        if ($shopCode === '' || $shopCode === $defaultShopCode || $shopCode === 'default') {
            return null;
        }

        $storeId = Store::query()->where('code', $shopCode)->value('id');

        return $storeId !== null ? (int) $storeId : null;
    }

    /**
     * Строка store_products для простой пер-магазинной цены: воспроизводит
     * upsertStoreProductPrice (инверсия discount_price -> price, база -> price_old,
     * отсутствие скидки -> price_old=null).
     */
    private function buildStorePriceRow(int $storeId, int $productId, array $item): array
    {
        $basePrice = round((float) $item['price'], 2);
        $discountPrice = isset($item['discount_price']) ? round((float) $item['discount_price'], 2) : null;

        return [
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $discountPrice ?? $basePrice,
            'price_old' => $discountPrice ? $basePrice : null,
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Строка store_products для остатка: quantity с множителем единицы
     * (кг -> граммы ×1000), как upsertStoreProductStock.
     */
    private function buildStoreStockRow(int $storeId, Product $product, array $item): array
    {
        return [
            'store_id' => $storeId,
            'product_id' => $product->id,
            'quantity' => $this->transformer->toStoredQuantity($item['quantity'] ?? 0, $product->unit_type?->value),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Shadow-сверка: сравнивает ожидаемые bulk-строки store_products с
     * фактически записанными per-item путём. notice (расхождение -> warning) —
     * чтобы пройти прод LOG_LEVEL. Валидация bulk на боевых данных до включения.
     *
     * @param  array<int, array<string, mixed>>  $shadowRows
     * @param  list<string>  $fields
     */
    private function logStoreShadowCompare(string $type, array $shadowRows, int $storeId, array $eventData, array $fields): void
    {
        $actual = StoreProduct::query()
            ->where('store_id', $storeId)
            ->whereIn('product_id', array_keys($shadowRows))
            ->get()
            ->keyBy('product_id');

        $diffs = [];
        foreach ($shadowRows as $productId => $expected) {
            $row = $actual->get($productId);
            if ($row === null) {
                $diffs[] = ['product_id' => $productId, 'reason' => 'no_row_written'];
                continue;
            }
            foreach ($fields as $f) {
                $exp = $expected[$f] ?? null;
                $got = $row->$f;
                $mismatch = ($exp === null || $got === null)
                    ? ($exp === null) !== ($got === null)
                    : abs((float) $exp - (float) $got) >= 0.001;
                if ($mismatch) {
                    $diffs[] = ['product_id' => $productId, 'field' => $f, 'expected' => $exp, 'actual' => $got];
                }
            }
        }

        $context = [
            'event_id' => $eventData['event_id'] ?? null,
            'shop_code' => $eventData['shop_code'] ?? null,
            'compared' => count($shadowRows),
            'diverged' => count($diffs),
            'diffs' => array_slice($diffs, 0, 20),
        ];

        if ($diffs !== []) {
            \Illuminate\Support\Facades\Log::warning("{$type}_shadow_compare", $context);
        } else {
            \Illuminate\Support\Facades\Log::notice("{$type}_shadow_compare", $context);
        }
    }

    private function storeProductRowsForDiff(bool $networkMode, array $eventData, array $resolved): Collection
    {
        if (! $networkMode || $resolved === []) {
            return collect();
        }

        $shopCode = (string) ($eventData['shop_code'] ?? '');
        $defaultShopCode = (string) config('rabbitmq-events.shop_code', 'default');

        if ($shopCode === '' || $shopCode === $defaultShopCode || $shopCode === 'default') {
            return collect();
        }

        $storeId = Store::query()->where('code', $shopCode)->value('id');

        if ($storeId === null) {
            return collect();
        }

        return StoreProduct::query()
            ->where('store_id', (int) $storeId)
            ->whereIn('product_id', array_map(
                static fn (array $row) => $row['product']->id,
                $resolved
            ))
            ->get()
            ->keyBy('product_id');
    }

    // =========================================================================
    // Общие хелперы батчей
    // =========================================================================

    /** @return array<string, int> */
    private function emptyStats(): array
    {
        return [
            'processed' => 0,
            'unchanged' => 0,
            'skipped_invalid' => 0,
            'skipped_missing' => 0,
            'skipped_stale' => 0,
            'errors' => 0,
            // MID-308, финальное ревью F4: отброшенные битые лесенки позиций
            // (price_batch_updated); при выключенном флаге и в остальных
            // типах батча остаётся 0.
            'tiers_dropped' => 0,
        ];
    }

    /**
     * Валидные уникальные позиции батча. Дубль id внутри сообщения:
     * первая позиция выигрывает (контракт, раздел 6).
     *
     * @param array<string, mixed> $eventData
     * @param array<string, int> $stats
     * @return Collection<int, array<string, mixed>>
     */
    private function uniqueItems(array $eventData, array &$stats): Collection
    {
        $raw = is_array($eventData['items'] ?? null) ? $eventData['items'] : [];

        $items = collect($raw)
            ->filter(static fn ($item) => is_array($item) && ! empty($item['id']))
            ->unique(static fn (array $item) => (string) $item['id'])
            ->values();

        $stats['skipped_invalid'] = count($raw) - $items->count();

        return $items;
    }

    /**
     * Bulk-резолв позиций: metadata (source + shop_code + external_id) →
     * товары одним SELECT на батч. Плюс per-item guard'ы идемпотентности
     * (повтор event_id, устаревший event_timestamp) — та же защита, что в
     * пайплайне пакета для одиночных событий.
     *
     * @param array<string, mixed> $eventData
     * @return array{0: list<array{item: array<string, mixed>, metadata: EventSyncMetadata, product: Product}>, 1: array<string, int>}
     */
    private function resolveItems(array $eventData): array
    {
        $stats = $this->emptyStats();
        $items = $this->uniqueItems($eventData, $stats);

        if ($items->isEmpty()) {
            return [[], $stats];
        }

        $eventTimestamp = isset($eventData['event_timestamp'])
            ? Carbon::parse($eventData['event_timestamp'])
            : null;

        $metadataQuery = EventSyncMetadata::query()
            ->where('entity_type', 'products')
            ->where('source', $eventData['source'])
            ->whereIn('external_id', $items->map(static fn (array $i) => (string) $i['id'])->all());

        // Сетевой режим: карточка глобальна — metadata резолвится без shop_code
        // (симметрично global_scope=true пакета для одиночных событий).
        // Иначе price/stock-батч магазина не нашёл бы карточку, приехавшую
        // событием без shop_code.
        if (! NetworkCatalog::enabled()) {
            $metadataQuery->where('shop_code', $eventData['shop_code'] ?? null);
        }

        $metadata = $metadataQuery->get()->keyBy('external_id');

        $products = Product::withTrashed()
            ->whereIn('id', $metadata->pluck('entity_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $resolved = [];

        foreach ($items as $item) {
            $meta = $metadata->get((string) $item['id']);

            if ($meta === null || ! $meta->entity_id || ! $products->has($meta->entity_id)) {
                // Карточка ещё не приезжала: цены/остатки товар не создают
                // (контракт: сначала карточки, потом цены/остатки).
                $stats['skipped_missing']++;
                continue;
            }

            if (isset($eventData['event_id']) && $meta->last_event_id === $eventData['event_id']) {
                $stats['skipped_stale']++; // повторная доставка того же сообщения
                continue;
            }

            if ($eventTimestamp && $meta->last_event_timestamp && $meta->last_event_timestamp->gt($eventTimestamp)) {
                $stats['skipped_stale']++; // защита от lost update
                continue;
            }

            $resolved[] = [
                'item' => $item,
                'metadata' => $meta,
                'product' => $products->get($meta->entity_id),
            ];
        }

        return [$resolved, $stats];
    }

    /**
     * Одиночный payload позиции: поля item + конвертные поля батча.
     * event_id/event_timestamp конверта дают идемпотентность повторной
     * доставки (metadata bump пишет именно их).
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function itemPayload(array $item, array $eventData, ?string $eventType = null): array
    {
        $envelope = [
            'event_id' => $eventData['event_id'] ?? null,
            'event_timestamp' => $eventData['event_timestamp'] ?? null,
            'event_source' => $eventData['event_source'] ?? ($eventData['source'] ?? null),
            'source' => $eventData['source'] ?? null,
            'shop_code' => $eventData['shop_code'] ?? null,
        ];

        if ($eventType !== null) {
            $envelope['event_type'] = $eventType;
        }

        return array_merge($item, array_filter($envelope, static fn ($v) => $v !== null));
    }

    /**
     * @param list<int> $metadataIds
     * @param array<string, mixed> $eventData
     */
    private function bumpMetadata(array $metadataIds, array $eventData): void
    {
        if ($metadataIds === []) {
            return;
        }

        $timestamp = isset($eventData['event_timestamp'])
            ? Carbon::parse($eventData['event_timestamp'])
            : now();

        foreach (array_chunk($metadataIds, 500) as $chunk) {
            EventSyncMetadata::query()->whereIn('id', $chunk)->update([
                'last_event_id' => $eventData['event_id'] ?? null,
                'last_event_timestamp' => $timestamp,
            ]);
        }
    }

    /**
     * Один сводный лог на сообщение (per-item логи стали бы бутылочным
     * горлышком на батчах по 500 позиций). Уровень notice: сводки должны
     * переживать LOG_LEVEL=notice, которым на время массовых выгрузок
     * глушится info/debug-шум пайплайна (~10 строк на товар).
     *
     * @param array<string, mixed> $eventData
     * @param array<string, int> $stats
     */
    private function logSummary(string $type, array $eventData, array $stats): void
    {
        Log::notice('Product batch processed', array_merge([
            'event_type' => $type,
            'event_id' => $eventData['event_id'] ?? null,
            'shop_code' => $eventData['shop_code'] ?? null,
            'items_total' => is_array($eventData['items'] ?? null) ? count($eventData['items']) : 0,
        ], $stats));
    }

    /**
     * @param array<string, mixed> $eventData
     * @param array<string, mixed> $item
     */
    private function logItemError(string $type, array $eventData, array $item, \Throwable $e): void
    {
        Log::error('Product batch item failed', [
            'event_type' => $type,
            'event_id' => $eventData['event_id'] ?? null,
            'shop_code' => $eventData['shop_code'] ?? null,
            'item_id' => $item['id'] ?? null,
            'sku' => $item['sku'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
