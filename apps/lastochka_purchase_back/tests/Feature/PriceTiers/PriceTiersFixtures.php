<?php

namespace Tests\Feature\PriceTiers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Status;
use App\Models\Type;
use App\Support\NetworkCatalogMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Фикстуры тестов «цены от количества» (MID-308) в purchase_back. Позиции заказа
 * создаются сырой вставкой: OrderItem::create() в этом репо принудительно ставит
 * status_id = WAITING.
 */
trait PriceTiersFixtures
{
  /** Лесенка в порциях из примера спеки: 279 от 1, 249 от 2, 199 от 5. */
  public static array $LADDER_FROM_ONE = [
    ['min_quantity' => 1, 'price' => 279],
    ['min_quantity' => 2, 'price' => 249],
    ['min_quantity' => 5, 'price' => 199],
  ];

  private function setModule(string $key, string $label, bool $enabled): void
  {
    $modules = json_decode(
      (string) DB::table('admin_settings')->where('key', 'project_modules')->value('value'),
      true
    ) ?: [];

    $modules[$key] = ['label' => $label, 'enabled' => $enabled];

    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );
  }

  protected function setPriceTiersFlag(bool $enabled): void
  {
    $this->setModule('price_tiers', 'Цены от количества', $enabled);
  }

  /** Флаг сетевого каталога кэшируется на 60 секунд: кэш сбрасывается вместе с записью. */
  protected function setNetworkCatalog(bool $enabled): void
  {
    $this->setModule('network_catalog', 'Сетевой каталог', $enabled);
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
  }

  protected function makeShop(string $title): int
  {
    return (int) DB::table('shops')->insertGetId([
      'title' => $title . ' ' . uniqid(),
      'code' => 'pt-' . uniqid(),
      'id_1c' => random_int(900000, 999999),
      'default' => 0,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  protected function makeClient(): int
  {
    return (int) DB::table('clients')->insertGetId([
      'password' => bcrypt('secret'),
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /** По умолчанию штучный товар без кванта. */
  protected function makeProduct(array $attrs = []): Product
  {
    $id = (int) DB::table('products')->insertGetId(array_merge([
      'title' => 'Tier product ' . uniqid(),
      'articul' => 'PT-' . uniqid(),
      'unit_type_id' => Type::UNIT_PIECE,
      'info_weight' => null,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
      'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));

    return Product::findOrFail($id);
  }

  protected function makePrice(int $productId, int $shopId, float $value, ?float $pricePerKg = null): int
  {
    return (int) DB::table('prices')->insertGetId([
      'product_id' => $productId,
      'shop_id' => $shopId,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => $value,
      'price_per_kg' => $pricePerKg,
      'step' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Живая лесенка магазина в таблице price_tiers (БАЗОВЫЕ единицы товара).
   *
   * @param  array<int, array{0: float|int, 1: float|int}>  $tiers  пары [min_quantity, value]
   */
  protected function makeLadder(int $productId, int $shopId, array $tiers): void
  {
    foreach ($tiers as [$minQuantity, $value]) {
      DB::table('price_tiers')->insert([
        'product_id' => $productId,
        'shop_id' => $shopId,
        'min_quantity' => $minQuantity,
        'value' => $value,
        'created_at' => now(),
        'updated_at' => now(),
      ]);
    }
  }

  protected function makeOrder(int $shopId, int $clientId): Order
  {
    return Order::factory()->create([
      'shop_id' => $shopId,
      'client_id' => $clientId,
      'payment_type_id' => 1,
      'status_id' => Status::ORDER_CREATED,
    ]);
  }

  /** price до сборки = цена за единицу (так пишет консьюмер события заказа). */
  protected function makeOrderItem(Order $order, Product $product, array $attrs = []): OrderItem
  {
    $id = (int) DB::table('order_items')->insertGetId(array_merge([
      'order_id' => $order->id,
      'product_id' => $product->id,
      'quantity' => 1,
      'price' => 0,
      'price_during_order' => 0,
      'price_old' => null,
      'price_tiers' => null,
      'is_eligible' => 1,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));

    return OrderItem::findOrFail($id);
  }

  /**
   * JSON снапшота для колонки order_items.price_tiers (форма раздела 3 обзора).
   *
   * @param  array<int, array{min_quantity: float|int, price: float|int}>  $tiers
   */
  protected function snapshotJson(array $tiers, float $basePrice = 279.0, ?float $basePriceOld = null, bool $baseIsEligible = true): string
  {
    return json_encode([
      'base_price' => $basePrice,
      'base_price_old' => $basePriceOld,
      'base_is_eligible' => $baseIsEligible,
      'tiers' => $tiers,
    ]);
  }
}
