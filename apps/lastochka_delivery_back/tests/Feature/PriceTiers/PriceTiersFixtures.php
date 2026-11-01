<?php

namespace Tests\Feature\PriceTiers;

use App\Models\Status;
use App\Models\Type;

/**
 * Фикстуры тестов «цены от количества» (MID-308). Сырые вставки: модели курьерки
 * частично guarded, а строки общей БД должны выглядеть так, как их пишут
 * admin_back и консьюмер событий.
 */
trait PriceTiersFixtures
{
  /** Лесенка в порциях из примера спеки: 279 от 1, 249 от 2, 199 от 5. */
  public static array $LADDER_FROM_ONE = [
    ['min_quantity' => 1, 'price' => 279],
    ['min_quantity' => 2, 'price' => 249],
    ['min_quantity' => 5, 'price' => 199],
  ];

  /** Переключить модуль price_tiers, не затирая остальные модули блоба. */
  protected function setPriceTiersFlag(bool $enabled): void
  {
    $modules = json_decode(
      (string) \DB::table('admin_settings')->where('key', 'project_modules')->value('value'),
      true
    ) ?: [];

    $modules['price_tiers'] = ['label' => 'Цены от количества', 'enabled' => $enabled];

    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );
  }

  /** ignore_stock = 1: списание остатков в collectOrderItems тестам цен не нужно. */
  protected function insertShop(array $attrs = []): int
  {
    return \DB::table('shops')->insertGetId(array_merge([
      'title' => 'Price tiers shop ' . uniqid(),
      'code' => 'pt-' . uniqid(),
      'id_1c' => random_int(900000, 999999),
      'default' => 0,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 1,
      'is_bonus_enabled' => 0,
      'bonus_spend_percent' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  protected function insertClient(): int
  {
    return \DB::table('clients')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  protected function insertUser(): int
  {
    return \DB::table('users')->insertGetId([
      'name' => 'Tier',
      'surname' => 'Сборщик ' . uniqid(),
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  protected function insertOrder(int $shopId, int $clientId, array $attrs = []): int
  {
    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $shopId,
      'client_id' => $clientId,
      'uuid' => 'pt-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'assembly_cost' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => Type::PAYMENT_CASH,
      'paid' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'status_id' => Status::ORDER_PREPARING,
      'amount' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  /** По умолчанию штучный товар без кванта. */
  protected function insertProduct(array $attrs = []): int
  {
    return \DB::table('products')->insertGetId(array_merge([
      'title' => 'Tier product ' . uniqid(),
      'articul' => 'PT-' . uniqid(),
      'unit_type_id' => Type::UNIT_PIECE,
      'info_weight' => null,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
      'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
      'status_id' => Status::CODE_ACTIVE,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  protected function insertPrice(int $productId, int $shopId, float $value): int
  {
    return \DB::table('prices')->insertGetId([
      'product_id' => $productId,
      'shop_id' => $shopId,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => $value,
      'step' => 1,
      'blocked' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Живая лесенка магазина в таблице price_tiers (БАЗОВЫЕ единицы товара).
   *
   * @param  array<int, array{0: float|int, 1: float|int}>  $tiers  пары [min_quantity, value]
   */
  protected function insertLadder(int $productId, int $shopId, array $tiers): void
  {
    foreach ($tiers as [$minQuantity, $value]) {
      \DB::table('price_tiers')->insert([
        'product_id' => $productId,
        'shop_id' => $shopId,
        'min_quantity' => $minQuantity,
        'value' => $value,
        'created_at' => now(),
        'updated_at' => now(),
      ]);
    }
  }

  /** price до сборки = цена за единицу (так пишет консьюмер события заказа). */
  protected function insertOrderItem(int $orderId, int $productId, array $attrs = []): int
  {
    return \DB::table('order_items')->insertGetId(array_merge([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 1,
      'price' => 0,
      'price_during_order' => 0,
      'price_old' => null,
      'price_tiers' => null,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'is_eligible' => 1,
      'is_supplemented' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  protected function insertAssembly(int $orderId, int $userId): int
  {
    return \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $userId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  protected function insertAssemblyItem(int $assemblyId, int $orderItemId, array $attrs = []): int
  {
    return \DB::table('assembly_items')->insertGetId(array_merge([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'status_id' => Status::PRODUCT_WAIT,
      'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
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
