<?php

namespace App\RabbitMQ\Handlers;

use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Type;
use App\Services\ModuleConfigService;
use App\Services\Pricing\PriceTierLadder;
use App\Services\ProductShopPrice\ProductShopPriceService;
use App\Transformers\ProductEventTransformer;
use Carbon\Carbon;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
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
class ProductBatchHandler
{
  public function __construct(private readonly ProductEventTransformer $transformer)
  {
  }

  public function handle(array $eventData): string
  {
    return match ($eventData['event_type'] ?? '') {
      'price_batch_updated' => $this->processPriceBatch($eventData),
      'stock_batch_updated' => $this->processStockBatch($eventData),
      'batch_updated' => $this->processProductBatch($eventData),
      default => 'ack', // неизвестный тип не блокирует очередь
    };
  }

  private function processPriceBatch(array $eventData): string
  {
    [$resolved, $stats] = $this->resolveItems($eventData);

    $bumpIds = [];
    $shopId = $this->resolveShopIdForDiff((string) ($eventData['shop_code'] ?? ''));

    $prices = collect();
    if ($shopId !== null && $resolved !== []) {
      $prices = Price::query()
        ->whereIn('product_id', array_map(static fn (array $row) => $row['product']->id, $resolved))
        ->where('shop_id', $shopId)
        ->where('type_id', Type::PRICE_DEFAULT)
        ->get()
        ->keyBy('product_id');
    }

    // Цены от количества (MID-308). Флаг читаем один раз на сообщение; без
    // известного магазина батч лесенки не ведёт (позиции идут полным путём,
    // ключ price_tiers обрабатывает трансформер). Существующие лесенки
    // магазина подгружаем одним запросом, рядом с ценами.
    //
    // $productIds — только для этого вызова: строку запроса цен выше (код до
    // фичи) не трогаем, чтобы не переписывать существующее тело.
    $tiersOn = $shopId !== null && ModuleConfigService::isPriceTiersEnabled();
    $tierService = app(ProductShopPriceService::class);
    $productIds = array_map(static fn (array $row) => $row['product']->id, $resolved);
    $ladders = ($tiersOn && $resolved !== [])
      ? $tierService->laddersForShop($shopId, $productIds)
      : [];
    $tierWrites = [];

    $bulkOn = $shopId !== null && (bool) config('rabbitmq-events.bulk_price_batch');
    $bulkRows = [];
    $bulkProductIds = [];

    // Shadow: пишем per-item, но считаем bulk-строку для сверки с фактом.
    $shadowOn = $shopId !== null && !$bulkOn && (bool) config('rabbitmq-events.bulk_price_batch_shadow');
    $shadowRows = [];

    foreach ($resolved as $row) {
      /** @var Product $product */
      $product = $row['product'];
      $item = $row['item'];

      try {
        if (!isset($item['price'])) {
          $stats['errors']++;
          continue;
        }

        // Guard: soft-delete НЕ отвязывает карточку от событий (matching идёт
        // через withTrashed), поэтому цену в удалённый товар не пишем ни bulk,
        // ни per-item путём. После дедупликации metadata перевешана на живые
        // карточки, но защита оставлена на случай неполного ремапа/гонки.
        if (method_exists($product, 'trashed') && $product->trashed()) {
          $stats['skipped_trashed']++;
          continue;
        }

        // MID-308: новая лесенка позиции либо null, когда писать нечего
        // (флаг выключен, ключа нет, лесенка битая или совпала с сохранённой).
        $newLadder = $tiersOn
          ? $this->changedLadder($item, $ladders[$product->id] ?? [], $eventData, $stats)
          : null;

        if ($shopId !== null && $this->priceUnchanged($product, $prices->get($product->id), $item)) {
          if ($newLadder !== null) {
            // MID-308: цена та же, изменилась только лесенка — пишем её одну,
            // без квант-пересчёта и fill-percent.
            $tierWrites[$product->id] = $newLadder;
            $stats['processed']++;
            $bumpIds[] = $row['metadata']->id;
            continue;
          }

          $stats['unchanged']++;
          $bumpIds[] = $row['metadata']->id;
          continue;
        }

        // Bulk-путь: штучные/не-квантовые позиции пишутся массово, минуя
        // per-item transformer. Квант/весовые/самоисцеление единицы — полный
        // путь. Eligibility (и resolveQuantum внутри) считаем ТОЛЬКО когда bulk
        // или shadow включены: при выключенном флаге путь идентичен исходному.
        $eligible = ($bulkOn || $shadowOn) && $this->isBulkEligiblePrice($product, $item);

        if ($bulkOn && $eligible) {
          $bulkRows[] = $this->buildSimplePriceRow($product, $item, $shopId);
          // fill_percent пересчитываем (async) ТОЛЬКО когда изменилось наличие
          // цены (value>0) — единственное, что влияет на ценовую компоненту
          // формулы. Обновление значения при уже существующей цене fill не
          // меняет, поэтому не грузим воркер холостыми пересчётами.
          if ($this->pricePresenceChanged($prices->get($product->id), $item)) {
            $bulkProductIds[] = $product->id;
          }
          if ($newLadder !== null) {
            $tierWrites[$product->id] = $newLadder;
          }
          $stats['processed']++;
          $bumpIds[] = $row['metadata']->id;
          continue;
        }

        if ($shadowOn && $eligible) {
          // не пишем bulk'ом, только запоминаем ожидаемую строку для сверки
          $shadowRows[$product->id] = $this->buildSimplePriceRow($product, $item, $shopId);
        }

        // MID-308: лесенку поштучной позиции батч пишет сам и ДО полного пути:
        // квант-пересчёт внутри processPriceUpdatedEvent может опубликовать
        // ProductUpdated, и карточное событие обязано нести уже новую лесенку.
        if ($newLadder !== null) {
          $tierService->replacePriceTiers($shopId, [$product->id => $newLadder]);
        }

        $this->transformer->processPriceUpdatedEvent($product, $this->priceItemPayload($item, $eventData, $tiersOn));

        $stats['processed']++;
        $bumpIds[] = $row['metadata']->id;
      } catch (\Throwable $e) {
        $stats['errors']++;
        $this->logItemError('price_batch_updated', $eventData, $item, $e);
      }
    }

    // upsert по UNIQUE(product_id, shop_id, type_id): одним стейтментом и новые,
    // и изменённые строки. Существующие неизменённые отсеяны priceUnchanged выше.
    foreach (array_chunk($bulkRows, 500) as $chunk) {
      Price::upsert(
        $chunk,
        ['product_id', 'shop_id', 'type_id'],
        ['value', 'price_without_discount', 'price_per_small_unit', 'step', 'price_per_kg', 'updated_at']
      );
    }

    // MID-308: изменённые лесенки bulk-позиций и позиций с прежней ценой —
    // пачкой после upsert цен: DELETE по (магазин, товары чанка) + INSERT.
    if ($tierWrites !== []) {
      $tierService->replacePriceTiers($shopId, $tierWrites);
    }

    // Отложенный bulk-пересчёт fill_percent: цена участвует в формуле
    // (ProductFillPercentService: наличие value>0 даёт вес price). Товары
    // грузим со связями пакетно и считаем без per-product fresh()
    // (recalculate(refresh:false)) — иначе вернули бы N тяжёлых перечиток.
    if ($bulkProductIds !== []) {
      // fill_percent пересчитываем асинхронно (джоба), чтобы тяжёлая гидратация
      // attributes/img/categories не блокировала дренаж батч-очереди. В тестах
      // queue=sync → выполнится inline, эквивалентность сохраняется.
      \App\Jobs\RecalculateProductFillPercentJob::dispatch(
        array_values(array_unique($bulkProductIds))
      );
    }

    if ($shadowRows !== []) {
      $this->logPriceShadowCompare($shadowRows, $shopId, $eventData);
    }

    $this->bumpMetadata($bumpIds, $eventData);
    $this->logSummary('price_batch_updated', $eventData, $stats);

    return 'ack';
  }

  /**
   * Shadow-сверка: сравнивает ожидаемые bulk-строки с фактически записанными
   * per-item путём. Расхождение = баг в bulk-расчёте, который увидим на боевых
   * данных ДО включения bulk. Читает только затронутые строки (по ключу).
   */
  private function logPriceShadowCompare(array $shadowRows, int $shopId, array $eventData): void
  {
    $actual = Price::query()
      ->whereIn('product_id', array_keys($shadowRows))
      ->where('shop_id', $shopId)
      ->where('type_id', Type::PRICE_DEFAULT)
      ->get()
      ->keyBy('product_id');

    $fields = ['value', 'price_without_discount', 'price_per_small_unit', 'step', 'price_per_kg'];
    $diffs = [];

    foreach ($shadowRows as $productId => $expected) {
      $price = $actual->get($productId);
      if ($price === null) {
        $diffs[] = ['product_id' => $productId, 'reason' => 'no_row_written'];
        continue;
      }

      foreach ($fields as $f) {
        $exp = $expected[$f] ?? null;
        $got = $price->$f;
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

    // notice — чтобы пройти прод-уровень логов (LOG_LEVEL=notice режет info);
    // расхождение (diverged>0) поднимаем до warning, чтобы бросалось в глаза.
    if ($diffs !== []) {
      Log::warning('price_batch_shadow_compare', $context);
    } else {
      Log::notice('price_batch_shadow_compare', $context);
    }
  }

  /**
   * Позиция пригодна для bulk-записи цены: штучный/не-квантовый товар,
   * строки цены ещё нет (INSERT-only), событие не несёт quantity_step и
   * measurement_unit (иначе нужен полный путь с квант-пересчётом или
   * самоисцелением единицы). Консервативно: любое сомнение → полный путь.
   */
  private function isBulkEligiblePrice(Product $product, array $item): bool
  {
    if (isset($item['quantity_step']) || isset($item['measurement_unit'])) {
      return false;
    }

    return app(\App\Services\Quantum\CategoryQuantumService::class)->resolveQuantum($product) === null;
  }

  /**
   * Строка prices для штучной позиции: воспроизводит итог per-item пути
   * (processPrice + CategoryQuantumService "else"-ветка без кванта) в памяти.
   * value = цена за базовую единицу, step=1, price_without_discount из скидки,
   * price_per_kg только для массы.
   */
  /**
   * Изменилось ли наличие положительной цены (value>0) — единственное, что
   * влияет на ценовую компоненту fill_percent (ProductFillPercentService).
   * Обновление значения при уже существующей цене fill не меняет.
   */
  private function pricePresenceChanged(?Price $existing, array $item): bool
  {
    $discount = isset($item['discount_price']) ? (float) $item['discount_price'] : 0.0;
    $newValue = $discount > 0 ? $discount : (float) ($item['price'] ?? 0);

    $oldHasPrice = $existing !== null && (float) $existing->value > 0;
    $newHasPrice = $newValue > 0;

    return $oldHasPrice !== $newHasPrice;
  }

  private function buildSimplePriceRow(Product $product, array $item, int $shopId): array
  {
    $price = (float) $item['price'];
    $discount = isset($item['discount_price']) ? (float) $item['discount_price'] : null;
    $effective = $discount > 0 ? $discount : $price;

    $baseUnit = $product->base_unit_type_id ?? $product->info_weight_type_id;
    $baseSize = \App\Services\Quantum\UnitFamily::sizeInSmallest($baseUnit);
    $perSmall = $effective > 0
      ? round($effective / $baseSize, \App\Services\Quantum\CategoryQuantumService::PER_SMALL_PRECISION)
      : null;

    $isMass = \App\Services\Quantum\UnitFamily::family($baseUnit) === \App\Services\Quantum\UnitFamily::MASS;

    return [
      'product_id' => $product->id,
      'shop_id' => $shopId,
      'type_id' => Type::PRICE_DEFAULT,
      // От сырой цены, а не от ₽/г: промежуточное округление теряло копейки.
      'value' => round(max(0, $effective), 2),
      'price_without_discount' => $discount > 0 ? $price : null,
      'price_per_small_unit' => $perSmall,
      'step' => 1,
      // price_per_kg держим в наборе всегда (единообразие колонок upsert):
      // для массы считаем, иначе null (как в processPrice для не-массы).
      'price_per_kg' => ($isMass && $perSmall !== null) ? round($effective * 1000 / $baseSize, 2) : null,
      'created_at' => now(),
      'updated_at' => now(),
    ];
  }

  /**
   * Новая лесенка позиции батча для записи (MID-308) либо null, когда писать
   * нечего: ключа price_tiers нет (ступени не трогаем), лесенка битая
   * (warning, цена позиции применяется) или совпала с сохранённой.
   * [] — валидный результат: лесенка товара удаляется.
   *
   * @param  array<int, array{min_quantity: float, price: float}>  $existing  сохранённая лесенка товара в магазине батча
   * @param  array<string, int>  $stats  сводная статистика батча (по ссылке): tiers_dropped++ на каждую отброшенную лесенку
   * @return array<int, array{min_quantity: float, price: float}>|null
   */
  private function changedLadder(array $item, array $existing, array $eventData, array &$stats): ?array
  {
    if (!array_key_exists('price_tiers', $item)) {
      return null;
    }

    $ladder = PriceTierLadder::normalize($item['price_tiers']);

    if ($ladder === null) {
      $stats['tiers_dropped']++;

      Log::warning('price_tiers dropped: invalid ladder, price applied without tiers', [
        'event_id' => $eventData['event_id'] ?? null,
        'shop_code' => $eventData['shop_code'] ?? null,
        'item_id' => $item['id'] ?? null,
        'price_tiers' => $item['price_tiers'],
      ]);

      return null;
    }

    return PriceTierLadder::same($ladder, $existing) ? null : $ladder;
  }

  /**
   * Payload поштучной позиции цены. Когда лесенки ведёт сам батч (MID-308:
   * флаг включён и магазин известен), ключ price_tiers из payload убирается,
   * иначе трансформер записал бы ту же лесенку второй раз.
   */
  private function priceItemPayload(array $item, array $eventData, bool $batchOwnsTiers): array
  {
    $payload = $this->itemPayload($item, $eventData);

    if ($batchOwnsTiers) {
      unset($payload['price_tiers']);
    }

    return $payload;
  }

  /**
   * Быстрый no-op: прайс магазина уже совпадает с событием.
   *
   * Консервативно: любое расхождение → полный путь (processPriceUpdatedEvent
   * с квант-пересчётом и fill-percent). Для весовых товаров с квантовыми
   * категориями Price.value хранит цену кванта (не сырую цену события) —
   * сравнение не совпадёт и товар уйдёт в полный путь: корректность важнее
   * оптимизации, no-op срабатывает для штучных (основной кейс алкомаркета).
   */
  private function priceUnchanged(Product $product, ?Price $price, array $item): bool
  {
    if ($price === null) {
      return false;
    }

    if (isset($item['quantity_step'])) {
      return false;
    }

    if (
      isset($item['measurement_unit'])
      && (int) $product->base_unit_type_id !== $this->transformer->mapMeasurementUnitToInfoWeightType((string) $item['measurement_unit'])
    ) {
      return false; // требуется самоисцеление единицы: полный путь
    }

    $base = (float) $item['price'];
    $discount = isset($item['discount_price']) ? (float) $item['discount_price'] : null;

    $expectedValue = $discount > 0 ? $discount : $base;
    $expectedWithout = $discount > 0 ? $base : null;

    if (abs((float) $price->value - $expectedValue) >= 0.001) {
      return false;
    }

    return $expectedWithout === null
      ? $price->price_without_discount === null
      : $price->price_without_discount !== null
        && abs((float) $price->price_without_discount - $expectedWithout) < 0.001;
  }

  private function processStockBatch(array $eventData): string
  {
    [$resolved, $stats] = $this->resolveItems($eventData);

    $bumpIds = [];
    $shopId = $this->resolveShopIdForDiff((string) ($eventData['shop_code'] ?? ''));

    $remains = collect();
    if ($shopId !== null && $resolved !== []) {
      $remains = Remain::query()
        ->whereIn('product_id', array_map(static fn (array $row) => $row['product']->id, $resolved))
        ->where('shop_id', $shopId)
        ->get()
        ->keyBy('product_id');
    }

    $bulkOn = $shopId !== null && (bool) config('rabbitmq-events.bulk_price_batch');
    $bulkRemains = [];

    foreach ($resolved as $row) {
      /** @var Product $product */
      $product = $row['product'];
      $item = $row['item'];

      try {
        if (!isset($item['quantity'])) {
          $stats['errors']++;
          continue;
        }

        // Guard: не пишем остаток в soft-deleted карточку (см. price-путь).
        if (method_exists($product, 'trashed') && $product->trashed()) {
          $stats['skipped_trashed']++;
          continue;
        }

        if ($shopId !== null && $this->stockUnchanged($remains->get($product->id), $item)) {
          $stats['unchanged']++;
          $bumpIds[] = $row['metadata']->id;
          continue;
        }

        // Стоковый путь без кванта/событий/fill-percent — любая позиция
        // bulk-пригодна: нормализуем отрицательные к 0 (как processQuantity).
        if ($bulkOn) {
          $bulkRemains[] = [
            'product_id' => $product->id,
            'shop_id' => $shopId,
            'quantity' => max(0, round((float) $item['quantity'], 3)),
            'created_at' => now(),
            'updated_at' => now(),
          ];
          $stats['processed']++;
          $bumpIds[] = $row['metadata']->id;
          continue;
        }

        $this->transformer->processStockUpdatedEvent($product, $this->itemPayload($item, $eventData));

        $stats['processed']++;
        $bumpIds[] = $row['metadata']->id;
      } catch (\Throwable $e) {
        $stats['errors']++;
        $this->logItemError('stock_batch_updated', $eventData, $item, $e);
      }
    }

    // upsert по UNIQUE(product_id, shop_id): новые и изменённые остатки разом.
    foreach (array_chunk($bulkRemains, 500) as $chunk) {
      Remain::upsert($chunk, ['product_id', 'shop_id'], ['quantity', 'updated_at']);
    }

    $this->bumpMetadata($bumpIds, $eventData);
    $this->logSummary('stock_batch_updated', $eventData, $stats);

    return 'ack';
  }

  /**
   * Полные карточки: каждая позиция идёт через штатный пайплайн пакета
   * (metadata INSERT-first, findExistingEntity, категории/pending refs,
   * conditional set). Оптимизация не нужна — карточки редкий поток;
   * выигрыш батча тут в транспорте, не в обработке.
   *
   * Один event_id на все позиции: дубль товара внутри батча отсекает
   * uniqueItems() ещё до вызова пайплайна («первая позиция выигрывает»);
   * повторная доставка сообщения (тот же event_id) — полный no-op
   * благодаря idempotency-guard'у самого пайплайна (per-entity
   * last_event_id в EventSyncMetadata).
   */
  private function processProductBatch(array $eventData): string
  {
    $stats = $this->emptyStats();
    $items = $this->uniqueItems($eventData, $stats);

    $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));

    foreach ($items as $item) {
      try {
        $consumer->processEvent($this->itemPayload($item, $eventData, 'updated'));
        $stats['processed']++;
      } catch (\Throwable $e) {
        $stats['errors']++;
        $this->logItemError('batch_updated', $eventData, $item, $e);
      }
    }

    $this->logSummary('batch_updated', $eventData, $stats);

    return 'ack';
  }

  /**
   * Быстрый no-op остатка: Remain магазина уже совпадает
   * (с нормализацией отрицательных к 0, как в processQuantity).
   */
  private function stockUnchanged(?Remain $remain, array $item): bool
  {
    if ($remain === null) {
      return false;
    }

    // Остаток — DECIMAL(14,3): сравниваем с точностью до грамма.
    return abs((float) $remain->quantity - max(0, (float) $item['quantity'])) < 0.0005;
  }

  /**
   * shop_id для diff-проверки. Повторяет первые правила
   * ResolvesShopCode::resolveShopId (default → 1, иначе shops.code).
   * null → магазин не найден: diff пропускается, позиции идут полным
   * путём (там резолвинг отработает штатно, поведение идентично
   * одиночному событию).
   */
  private function resolveShopIdForDiff(string $shopCode): ?int
  {
    if ($shopCode === config('rabbitmq-events.shop_code', 'default') || $shopCode === 'default') {
      return 1;
    }

    $id = Shop::query()->where('code', $shopCode)->value('id');

    return $id !== null ? (int) $id : null;
  }

  // =========================================================================
  // Общие хелперы батчей
  // =========================================================================

  private function emptyStats(): array
  {
    return [
      'processed' => 0,
      'unchanged' => 0,
      'skipped_invalid' => 0,
      'skipped_missing' => 0,
      'skipped_stale' => 0,
      'skipped_trashed' => 0,
      // MID-308: битые лесенки price_batch_updated, отброшенные changedLadder()
      // (Log::warning на каждую) — счётчик виден в сводном логе батча.
      'tiers_dropped' => 0,
      'errors' => 0,
    ];
  }

  /**
   * Валидные уникальные позиции батча. Дубль id внутри сообщения:
   * первая позиция выигрывает (контракт, раздел 6).
   */
  private function uniqueItems(array $eventData, array &$stats): Collection
  {
    $raw = is_array($eventData['items'] ?? null) ? $eventData['items'] : [];

    $items = collect($raw)
      ->filter(static fn ($item) => is_array($item) && !empty($item['id']))
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
   * @return array{0: list<array{item: array, metadata: EventSyncMetadata, product: Product}>, 1: array}
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
    if (!app(\App\NetworkCatalog\Support\NetworkCatalogMode::class)->isEnabled()) {
      $metadataQuery->where('shop_code', $eventData['shop_code']);
    }

    $metadata = $metadataQuery->get()->keyBy('external_id');

    $products = Product::withTrashed()
      ->whereIn('id', $metadata->pluck('entity_id')->filter()->all())
      ->get()
      ->keyBy('id');

    $resolved = [];

    foreach ($items as $item) {
      $meta = $metadata->get((string) $item['id']);

      if ($meta === null || !$meta->entity_id || !$products->has($meta->entity_id)) {
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
