<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;

/**
 * Фикстуры тестов «диапазоны стоимости доставки» (MID-280). Сырые вставки: модели
 * курьерки частично guarded, а строки общей БД должны выглядеть так, как их пишут
 * admin_back и консьюмер событий.
 */
trait DeliveryPriceRangesFixtures
{
  /** Ступени из примера задачи: от 1000 → 100, от 3000 → бесплатно (цена зоны 200). */
  public static array $TASK_RANGES = [
    ['from_amount' => 1000, 'price' => 100],
    ['from_amount' => 3000, 'price' => 0],
  ];

  /**
   * JSON снимка для колонки orders.delivery_price_snapshot (форма раздела 1.2 обзора).
   *
   * @param  array<int, array{from_amount: int, price: int}>  $ranges
   */
  protected function snapshotJson(int $checkoutPrice, ?array $ranges = null, int $basePrice = 200): string
  {
    return json_encode([
      'base_price' => $basePrice,
      'ranges' => $ranges ?? self::$TASK_RANGES,
      'checkout_price' => $checkoutPrice,
    ]);
  }

  /** ignore_stock = 1: списание остатков в collectOrderItems этим тестам не нужно. */
  protected function insertShop(array $attrs = []): int
  {
    return \DB::table('shops')->insertGetId(array_merge([
      'title' => 'Delivery ranges shop ' . uniqid(),
      'code' => 'dpr-' . uniqid(),
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

  /** Сборщик со сменой в магазине (User в этом репо guarded: сырой insert). */
  protected function insertCollector(int $shopId): User
  {
    $userId = \DB::table('users')->insertGetId([
      'name' => 'Collector',
      'surname' => 'Диапазоны ' . uniqid(),
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $user = User::findOrFail($userId);
    $user->assignRole(Role::findOrCreate('collector', 'api'));

    \DB::table('shifts')->insert([
      'user_id' => $user->id,
      'shop_id' => $shopId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return $user;
  }

  protected function insertOrder(int $shopId, int $clientId, array $attrs = []): int
  {
    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $shopId,
      'client_id' => $clientId,
      'uuid' => 'dpr-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'assembly_cost' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => Type::PAYMENT_OFFLINE,
      'paid' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'status_id' => Status::ORDER_PREPARING,
      'amount' => 0,
      'is_heavy' => 0,
      'slot_start_date' => now(),
      'slot_end_date' => now(),
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  protected function insertProduct(): int
  {
    return \DB::table('products')->insertGetId([
      'title' => 'Delivery ranges product ' . uniqid(),
      'articul' => 'DPR-' . uniqid(),
      'unit_type_id' => Type::UNIT_PIECE,
      'status_id' => Status::CODE_ACTIVE,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /** price до сборки = цена за единицу (так пишет консьюмер события заказа). */
  protected function insertOrderItem(int $orderId, float $unitPrice, int $quantity = 1, array $attrs = []): int
  {
    return \DB::table('order_items')->insertGetId(array_merge([
      'order_id' => $orderId,
      'product_id' => $this->insertProduct(),
      'quantity' => $quantity,
      'price' => $unitPrice,
      'price_during_order' => $unitPrice,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'is_eligible' => 1,
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

  /** Позиция сборки: quantity = собранное количество (штучный товар). */
  protected function insertAssemblyItem(int $assemblyId, int $orderItemId, int $quantity, int $statusId = Status::PRODUCT_ASSEMBLED): int
  {
    return \DB::table('assembly_items')->insertGetId([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => $quantity,
      'status_id' => $statusId,
      'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Зона доставки с квадратным полигоном 55.0–55.1 × 37.0–37.1 (точка 55.05, 37.05 внутри).
   *
   * @param  array<int, array{from_amount: int, price: int}>|null  $ranges
   */
  protected function insertZone(int $shopId, int $deliveryPrice, ?array $ranges, array $attrs = []): int
  {
    $zoneId = \DB::table('shop_zones')->insertGetId(array_merge([
      'shop_id' => $shopId,
      'name' => 'Зона ' . uniqid(),
      'delivery_price' => $deliveryPrice,
      'min_order_price' => 0,
      'free_delivery_from' => null,
      'delivery_price_ranges' => $ranges !== null ? json_encode($ranges) : null,
      'delivery_type' => 'own',
      'is_active' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));

    foreach ([[55.0, 37.0], [55.0, 37.1], [55.1, 37.1], [55.1, 37.0]] as $i => [$lat, $lng]) {
      \DB::table('zone_coordinates')->insert([
        'shop_zone_id' => $zoneId,
        'latitude' => $lat,
        'longitude' => $lng,
        'position' => $i + 1,
        'created_at' => now(),
        'updated_at' => now(),
      ]);
    }

    return $zoneId;
  }

  /** Адрес заказа внутри полигона insertZone(). */
  protected function insertClientInfo(int $orderId, float $lat = 55.05, float $lng = 37.05): void
  {
    \DB::table('client_order_infos')->insert([
      'order_id' => $orderId,
      'name' => 'Тест',
      'address' => 'Адрес',
      'latitude' => $lat,
      'longitude' => $lng,
      'first_order' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /** Переключить collector_payment, не затирая остальные модули блоба. */
  protected function setCollectorPaymentModule(bool $enabled): void
  {
    $modules = json_decode(
      (string) \DB::table('admin_settings')->where('key', 'project_modules')->value('value'),
      true
    ) ?: [];

    $modules['collector_payment'] = ['label' => 'Списание оплаты сборщиком', 'enabled' => $enabled];

    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );
  }

  protected function orderRow(int $orderId): object
  {
    return \DB::table('orders')->where('id', $orderId)->first();
  }
}
