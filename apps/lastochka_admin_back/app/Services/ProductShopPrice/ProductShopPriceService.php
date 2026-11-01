<?php

namespace App\Services\ProductShopPrice;

use App\Events\ProductUpdated;
use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Type;
use App\Rules\PriceTiersRule;
use App\Services\ModuleConfigService;
use App\Services\Pricing\PriceTierLadder;
use App\Services\Quantum\CategoryQuantumService;
use App\Services\Quantum\UnitFamily;
use App\Support\StockUnits;
use Comrades\RabbitMQEvents\Facades\RabbitMQEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Цена и остаток товара в разрезе магазина (сетевой каталог).
 *
 * Пишет djami prices/remains по (product_id, shop_id) и публикует
 * пер-магазинные события price_updated/stock_updated — витрина превращает
 * их в оффер store_products (тот же механизм, что у потока 1С).
 */
class ProductShopPriceService
{
  /**
   * Upsert цены и остатка магазина + публикация событий оффера.
   *
   * Шаг (квант) существующей строки сохраняем — как updatePrices()
   * в ProductUpdate (LASTOCHKI-JAMI-11200); чтение и запись под
   * lockForUpdate, чтобы не гонять с RabbitMQ-консьюмером (processPrice).
   *
   * @param  array<int, array{min_quantity: float, price: float}>|null  $priceTiers  лесенка магазина (MID-308): null — не трогаем, [] — очистить
   */
  public function save(Product $product, Shop $shop, float $price, float $quantity, ?array $priceTiers = null): Price
  {
    // Инвариант «price > 0» соблюдаем на уровне сервиса (defense-in-depth):
    // save() — общая точка записи для будущих GraphQL-мутаций, а не только
    // текущих @rules валидаторов. publishOfferEvents() этот guard не трогает —
    // там price=0 легитимен (delete()-семантика задачи 3).
    if ($price <= 0) {
      throw new \InvalidArgumentException('Цена должна быть больше нуля');
    }

    // MID-308: запись лесенки может столкнуться с консьюмером цен 1С по той же
    // паре (товар, магазин). Deadlock переживаем повтором всей транзакции:
    // повторить можно только внешнюю. Без лесенки число попыток прежнее.
    $attempts = $priceTiers !== null ? 3 : 1;

    return DB::transaction(function () use ($product, $shop, $price, $quantity, $priceTiers) {
      $priceRow = $this->saveWithoutTransaction($product, $shop, $price, $quantity, publishOffers: false);

      if ($priceTiers !== null) {
        $this->syncPriceTiers($product->id, $shop->id, $priceTiers);
      }

      // Карточка вперёд офферов — см. publishShowcaseCard().
      $this->publishShowcaseCard($product);
      $this->publishOfferEvents($product, $shop, round($price, 2), max(0, $quantity), $priceTiers);

      return $priceRow;
    }, $attempts);
  }

  /**
   * Задать цену/остаток во всех активных магазинах с реальным кодом.
   *
   * Чанки по 200 магазинов, каждый чанк в своей транзакции: upsert
   * идемпотентен, поэтому повтор после сбоя безопасен. Batch-события 1С
   * здесь не применимы (их конверт: один shop_code — много товаров,
   * у нас наоборот), поэтому пара единичных событий на магазин.
   *
   * Guard «price > 0» проверяем один раз до начала цикла по магазинам —
   * saveWithoutTransaction() его не дублирует (см. её докблок).
   *
   * @param  array<int, array{min_quantity: float, price: float}>|null  $priceTiers  одна лесенка на все магазины (MID-308): null — не трогаем, [] — очистить
   */
  public function applyToAll(Product $product, float $price, float $quantity, ?array $priceTiers = null): int
  {
    if ($price <= 0) {
      throw new \InvalidArgumentException('Цена должна быть больше нуля');
    }

    // Карточка одна на всю операцию: публикуем до цикла, иначе на каждый из
    // сотен магазинов уедет тяжёлое карточное событие. Порядок сохраняется —
    // outbox публикуется по возрастанию id.
    DB::transaction(fn () => $this->publishShowcaseCard($product));

    $processed = 0;

    Shop::query()
      ->whereNotNull('code')
      ->where('code', '!=', '')
      ->orderBy('id')
      ->chunkById(200, function ($shops) use ($product, $price, $quantity, $priceTiers, &$processed) {
        // MID-308: с лесенкой транзакция чанка повторяется после deadlock (до
        // трёх попыток), и повтор не должен посчитать магазины чанка дважды.
        $processedBefore = $processed;

        DB::transaction(function () use ($shops, $product, $price, $quantity, $priceTiers, $processedBefore, &$processed) {
          $processed = $processedBefore;

          // MID-308: лесенку пишем до цикла — офферные события магазинов
          // публикуются внутри saveWithoutTransaction().
          if ($priceTiers !== null) {
            $this->replacePriceTiersForShops($product->id, $shops->pluck('id')->all(), $priceTiers);
          }

          // MID-308: лесенки для офферных событий чанка. Переданная лесенка одна
          // на все магазины; иначе сохранённые лесенки товара читаются одним
          // запросом на чанк и только при включённом модуле (при выключенном
          // к таблице ступеней не обращаемся вовсе).
          $chunkLadders = ($priceTiers === null && ModuleConfigService::isPriceTiersEnabled())
            ? ($this->laddersByProductAndShop([(int) $product->id], $shops->pluck('id')->all())[(int) $product->id] ?? [])
            : [];

          foreach ($shops as $shop) {
            $this->saveWithoutTransaction(
              $product,
              $shop,
              $price,
              $quantity,
              true,
              $priceTiers ?? ($chunkLadders[$shop->id] ?? [])
            );
            $processed++;
          }
        }, $priceTiers !== null ? 3 : 1);
      });

    return $processed;
  }

  /**
   * Тело save() без собственной транзакции и без guard'а price>0.
   *
   * Вызывающая сторона обязана: 1) обеспечить транзакцию, 2) проверить
   * price>0 заранее. save() делает оба на каждый вызов; applyToAll()
   * оборачивает чанк в одну транзакцию и проверяет guard один раз до цикла —
   * так на чанк из 200 магазинов не создаётся 200 savepoint'ов.
   */
  private function saveWithoutTransaction(
    Product $product,
    Shop $shop,
    float $price,
    float $quantity,
    bool $publishOffers = true,
    ?array $ladder = null
  ): Price {
    // withTrashed: строку могли мягко удалить через delete() («убрать товар из
    // магазина»), а UNIQUE-индекс её всё равно занимает — см. findForUpsert().
    $existing = Price::findForUpsert($product->id, $shop->id, Type::PRICE_DEFAULT);
    $fields = $this->portionPriceFields($product, $price);

    if ($existing !== null) {
      // Шаг существующей строки не трогаем: его ведёт пересчёт фасовки.
      unset($fields['step']);
      $existing->reviveWith($fields);
      $priceRow = $existing;
    } else {
      $priceRow = Price::create(array_merge([
        'product_id' => $product->id,
        'shop_id' => $shop->id,
        'type_id' => Type::PRICE_DEFAULT,
      ], $fields));
    }

    Remain::upsertQuantity($product->id, $shop->id, max(0, $quantity));

    // save() публикует офферы сам — после карточки, чтобы не обогнать её.
    if ($publishOffers) {
      $this->publishOfferEvents($product, $shop, round($price, 2), max(0, $quantity), $ladder);
    }

    return $priceRow;
  }

  /**
   * Поля строки цены из введённой цены ПОРЦИИ: цена за наименьшую единицу и за
   * кг (база пересчёта фасовки) и шаг порции. Раньше писался только value — и
   * следующий пересчёт фасовки по старой цене за грамм откатывал ручную цену,
   * а новая строка получала step = 1 при порции 300 г (шаг ставим только новой).
   *
   * @return array<string, float|int|null>
   */
  private function portionPriceFields(Product $product, float $price): array
  {
    $portion = StockUnits::portionInSmallest($product);
    $fields = [
      'value' => round($price, 2),
      'price_per_small_unit' => round($price / $portion, CategoryQuantumService::PER_SMALL_PRECISION),
      'step' => (int) ($product->info_weight ?? 0) > 0 ? (int) $product->info_weight : 1,
    ];

    if (UnitFamily::family(StockUnits::stockUnit($product)) === UnitFamily::MASS) {
      $fields['price_per_kg'] = round($price * 1000 / $portion, 2);
    }

    return $fields;
  }

  /**
   * Карточка товара на витрину.
   *
   * Витрина строит оффер (store_products) только для уже существующего у неё
   * товара: пер-магазинное price_updated/stock_updated на неизвестный товар
   * consumer отбрасывает с ack и без ретрая («Custom event received for
   * non-existent entity»), и цена теряется навсегда. Поэтому карточка всегда
   * публикуется перед офферами — если товара на витрине ещё не было, он
   * появится тем же пакетом событий.
   */
  private function publishShowcaseCard(Product $product): void
  {
    event(new ProductUpdated($product));
  }

  /**
   * Пер-магазинные события оффера. Идентичность (id/source) пакет резолвит
   * сам через event_sync_metadata: для 1С-товара уедет его GUID. shop_code
   * магазина в payload кладёт конверт пакета (5-й аргумент dispatch).
   *
   * Цены от количества (MID-308): при включённом модуле price_updated несёт
   * ключ price_tiers с ПОЛНОЙ лесенкой магазина (ключ цены ступени — price,
   * единицы — базовые единицы товара, [] — лесенки нет). При выключенном
   * модуле ключа в событии нет и таблица ступеней не читается: на форке в окне
   * между выкладкой кода и миграцией её ещё нет.
   *
   * @param  array<int, array{min_quantity: float, price: float}>|null  $ladder  уже известная лесенка магазина; null — прочитать сохранённую (один запрос)
   */
  public function publishOfferEvents(Product $product, Shop $shop, float $price, float $quantity, ?array $ladder = null): void
  {
    $pricePayload = ['price' => $price];

    if (ModuleConfigService::isPriceTiersEnabled()) {
      $pricePayload['price_tiers'] = $ladder ?? $this->ladderFor((int) $product->id, (int) $shop->id);
    }

    RabbitMQEvents::dispatch('products', $product, 'price_updated', $pricePayload, $shop->code);
    RabbitMQEvents::dispatch('products', $product, 'stock_updated', ['quantity' => $quantity], $shop->code);
  }

  // =========================================================================
  // Цены от количества (MID-308): хранение лесенки пары (товар, магазин)
  // =========================================================================

  /**
   * Лесенка из аргумента price_tiers GraphQL-мутации: null — аргумент не
   * передан или равен null, лесенку не трогаем; иначе нормализованная лесенка
   * ([] — очистить).
   *
   * Основную валидацию с ключами под parseGqlErrors делает PriceTiersRule на
   * схеме; здесь defense-in-depth для вызовов резолвера мимо валидатора — как
   * guard «price > 0» в save().
   *
   * @param  array<string, mixed>  $args  аргументы мутации; ступени в ключах min_quantity / value
   * @return array<int, array{min_quantity: float, price: float}>|null
   *
   * @throws ValidationException
   */
  public function ladderFromArgs(array $args): ?array
  {
    if (!array_key_exists('price_tiers', $args) || $args['price_tiers'] === null) {
      return null;
    }

    if (!ModuleConfigService::isPriceTiersEnabled()) {
      throw ValidationException::withMessages(['price_tiers' => [PriceTiersRule::MESSAGE_DISABLED]]);
    }

    $ladder = PriceTierLadder::normalize($args['price_tiers'], 'value');

    if ($ladder === null) {
      throw ValidationException::withMessages(['price_tiers' => [PriceTiersRule::MESSAGE_INVALID]]);
    }

    if (!PriceTierLadder::isStrictlyDecreasing($ladder)) {
      throw ValidationException::withMessages(['price_tiers' => [PriceTiersRule::MESSAGE_NOT_DECREASING]]);
    }

    return $ladder;
  }

  /**
   * Есть ли у пары (товар, магазин) действующая цена: живая строка prices
   * типа PRICE_DEFAULT со значением больше нуля. Мягко удалённая строка
   * (оффер снят через delete()) ценой не считается.
   */
  public function pairHasPrice(int $productId, int $shopId): bool
  {
    return Price::query()
      ->where('product_id', $productId)
      ->where('shop_id', $shopId)
      ->where('type_id', Type::PRICE_DEFAULT)
      ->where('value', '>', 0)
      ->exists();
  }

  /**
   * Лесенка требует цены (спека 8.2): непустую лесенку нельзя записать паре,
   * у которой нет цены и цена не передана в этой же мутации. Иначе в сетевом
   * каталоге ушло бы офферное событие с price = 0, и витрина сняла бы оффер.
   * Пустая лесенка ([] — очистить) цены не требует: снимать ступени можно
   * всегда.
   *
   * ProductShopPriceSave и ProductShopPricesApplyToAll этой проверки не
   * требуют: у них price обязателен по схеме (Float!) и больше нуля.
   *
   * @param  array<int, array{min_quantity: float, price: float}>|null  $ladder  результат ladderFromArgs()
   *
   * @throws ValidationException
   */
  public function assertLadderHasPrice(?array $ladder, bool $priceGiven, ?int $productId, ?int $shopId): void
  {
    if ($ladder === null || $ladder === [] || $priceGiven) {
      return;
    }

    if ($productId !== null && $shopId !== null && $this->pairHasPrice($productId, $shopId)) {
      return;
    }

    throw ValidationException::withMessages(['price_tiers' => [PriceTiersRule::MESSAGE_PRICE_REQUIRED]]);
  }

  /**
   * Лесенка пары (товар, магазин) в нормализованном виде PriceTierLadder:
   * [['min_quantity' => float, 'price' => float], ...] по возрастанию порога.
   * Нет ступеней: [].
   *
   * @return array<int, array{min_quantity: float, price: float}>
   */
  public function ladderFor(int $productId, int $shopId): array
  {
    return $this->laddersForShop($shopId, [$productId])[$productId] ?? [];
  }

  /**
   * Лесенки нескольких товаров в разрезе магазинов одним запросом:
   * [product_id => [shop_id => лесенка]]. Для массовой публикации офферов
   * («Применить ко всем», products:resync-showcase), чтобы не читать лесенку
   * отдельным запросом на каждый магазин.
   *
   * @param  array<int, int>  $productIds
   * @param  array<int, int>|null  $shopIds  ограничить магазинами; null — все магазины
   * @return array<int, array<int, array<int, array{min_quantity: float, price: float}>>>
   */
  public function laddersByProductAndShop(array $productIds, ?array $shopIds = null): array
  {
    if ($productIds === [] || $shopIds === []) {
      return [];
    }

    $rows = DB::table('price_tiers')
      ->whereIn('product_id', array_values(array_unique($productIds)))
      ->when($shopIds !== null, static fn ($query) => $query->whereIn('shop_id', array_values(array_unique($shopIds))))
      ->orderBy('product_id')
      ->orderBy('shop_id')
      ->orderBy('min_quantity')
      ->get(['product_id', 'shop_id', 'min_quantity', 'value']);

    $ladders = [];

    foreach ($rows as $row) {
      $ladders[(int) $row->product_id][(int) $row->shop_id][] = [
        'min_quantity' => round((float) $row->min_quantity, 3),
        'price' => round((float) $row->value, 2),
      ];
    }

    return $ladders;
  }

  /**
   * Лесенки магазина по товарам одним запросом: [product_id => лесенка].
   * Товары без ступеней в результате отсутствуют.
   *
   * @param  array<int, int>  $productIds
   * @return array<int, array<int, array{min_quantity: float, price: float}>>
   */
  public function laddersForShop(int $shopId, array $productIds): array
  {
    if ($productIds === []) {
      return [];
    }

    $rows = DB::table('price_tiers')
      ->where('shop_id', $shopId)
      ->whereIn('product_id', array_values(array_unique($productIds)))
      ->orderBy('product_id')
      ->orderBy('min_quantity')
      ->get(['product_id', 'min_quantity', 'value']);

    $ladders = [];

    foreach ($rows as $row) {
      $ladders[(int) $row->product_id][] = [
        'min_quantity' => round((float) $row->min_quantity, 3),
        'price' => round((float) $row->value, 2),
      ];
    }

    return $ladders;
  }

  /**
   * Полная замена лесенки пары (товар, магазин). Совпавшая лесенка не
   * переписывается.
   *
   * @param  array<int, array{min_quantity: float, price: float}>  $ladder  результат PriceTierLadder::normalize(); [] удаляет лесенку
   * @return bool true, если лесенка изменилась
   */
  public function syncPriceTiers(int $productId, int $shopId, array $ladder): bool
  {
    if (PriceTierLadder::same($ladder, $this->ladderFor($productId, $shopId))) {
      return false;
    }

    $this->replacePriceTiers($shopId, [$productId => $ladder]);

    return true;
  }

  /**
   * Пакетная замена лесенок одного магазина: DELETE по (магазин, товары
   * чанка) и массовый INSERT. Через DB::table: у строк нет модельных событий,
   * а батч цен 1С несёт сотни позиций на сообщение.
   *
   * Транзакция с тремя попытками: консьюмер цен 1С и мутация админки могут
   * одновременно переписывать лесенку одной пары, и DELETE + INSERT по
   * UNIQUE-индексу даёт deadlock. Повтор срабатывает только на верхнем уровне
   * транзакций (так устроен Laravel): вызывающий, у которого уже открыта своя
   * транзакция, обязан сам задать ей три попытки. Так сделано в save(),
   * applyToAll() и delete(); одиночные события консьюмера пакет оборачивает в
   * DB::transaction(..., attempts: 3) сам (EventConsumer::processEvent()).
   *
   * @param  array<int, array<int, array{min_quantity: float, price: float}>>  $laddersByProductId  [product_id => лесенка]; [] удаляет лесенку товара
   */
  public function replacePriceTiers(int $shopId, array $laddersByProductId): void
  {
    foreach (array_chunk($laddersByProductId, 500, true) as $chunk) {
      $now = now();
      $rows = [];

      foreach ($chunk as $productId => $ladder) {
        foreach ($ladder as $tier) {
          $rows[] = $this->buildTierRow((int) $productId, $shopId, $tier, $now);
        }
      }

      DB::transaction(function () use ($shopId, $chunk, $rows) {
        DB::table('price_tiers')
          ->where('shop_id', $shopId)
          ->whereIn('product_id', array_keys($chunk))
          ->delete();

        foreach (array_chunk($rows, 1000) as $insertChunk) {
          DB::table('price_tiers')->insert($insertChunk);
        }
      }, 3);
    }
  }

  /**
   * Одна лесенка товара сразу в нескольких магазинах («Применить ко всем»):
   * один DELETE и массовый INSERT на чанк магазинов вместо пары запросов на
   * магазин. Транзакцию с тремя попытками обеспечивает вызывающая сторона
   * (applyToAll).
   *
   * @param  array<int, int>  $shopIds
   * @param  array<int, array{min_quantity: float, price: float}>  $ladder
   */
  public function replacePriceTiersForShops(int $productId, array $shopIds, array $ladder): void
  {
    if ($shopIds === []) {
      return;
    }

    DB::table('price_tiers')
      ->where('product_id', $productId)
      ->whereIn('shop_id', $shopIds)
      ->delete();

    $now = now();
    $rows = [];

    foreach ($shopIds as $shopId) {
      foreach ($ladder as $tier) {
        $rows[] = $this->buildTierRow($productId, (int) $shopId, $tier, $now);
      }
    }

    foreach (array_chunk($rows, 1000) as $insertChunk) {
      DB::table('price_tiers')->insert($insertChunk);
    }
  }

  /**
   * Строка INSERT таблицы price_tiers для одной ступени пары (товар, магазин).
   * Общий билдер replacePriceTiers() и replacePriceTiersForShops() (MID-308).
   *
   * @param  array{min_quantity: float, price: float}  $tier
   */
  private function buildTierRow(int $productId, int $shopId, array $tier, $now): array
  {
    return [
      'product_id' => $productId,
      'shop_id' => $shopId,
      'min_quantity' => $tier['min_quantity'],
      'value' => $tier['price'],
      'created_at' => $now,
      'updated_at' => $now,
    ];
  }

  /**
   * Убрать товар из магазина: soft-delete строк цены/остатка и публикация
   * нулевого оффера (price=0 деактивирует оффер на витрине: purchasable
   * требует price > 0). Идемпотентно: отсутствие строк — не ошибка.
   */
  public function delete(Product $product, Shop $shop): bool
  {
    // MID-308: снятие оффера удаляет и лесенку пары, но только при включённом
    // модуле: при выключенном к таблице ступеней не обращаемся вовсе. Запись
    // лесенки внутри транзакции, поэтому три попытки задаёт внешняя транзакция.
    $tiersOn = ModuleConfigService::isPriceTiersEnabled();

    return DB::transaction(function () use ($product, $shop, $tiersOn) {
      Price::query()
        ->where('product_id', $product->id)
        ->where('shop_id', $shop->id)
        ->where('type_id', Type::PRICE_DEFAULT)
        ->lockForUpdate()
        ->get()
        ->each->delete();

      Remain::query()
        ->where('product_id', $product->id)
        ->where('shop_id', $shop->id)
        ->lockForUpdate()
        ->get()
        ->each->delete();

      if ($tiersOn) {
        $this->replacePriceTiers((int) $shop->id, [(int) $product->id => []]);
      }

      // При включённом модуле событие несёт price_tiers: [] (лесенка снята
      // вместе с оффером), при выключенном ключа в событии нет.
      $this->publishOfferEvents($product, $shop, 0.0, 0, []);

      return true;
    }, $tiersOn ? 3 : 1);
  }
}
