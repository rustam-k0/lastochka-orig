<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Регрессионный тест scopeDaily (LASTOCHKI-JAMI-11141).
 *
 * Вкладка «Доставлено» курьера (orders_paginate status=ORDER_FINISHED, scope daily)
 * отбирала доставленные заказы по slot_start_date >= начало дня. Заказ, доставленный
 * сегодня по вчерашнему слоту, исчезал из выдачи сразу после доставки.
 */
class OrderDailyScopeTest extends TestCase
{
  use DatabaseTransactions;

  private ?int $clientId = null;
  private ?int $shopId = null;
  private ?int $b2bShopId = null;

  private function insertShop(bool $isB2b): int
  {
    return \DB::table('shops')->insertGetId([
      'title' => $isB2b ? 'Test b2b shop' : 'Test shop',
      'id_1c' => $isB2b ? 999998 : 999999,
      'default' => 0,
      'is_b2b' => $isB2b ? 1 : 0,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 0,
      'is_bonus_enabled' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function insertOrder(array $attributes): int
  {
    $this->clientId ??= \DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->shopId ??= $this->insertShop(false);

    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $this->shopId,
      'uuid' => 'test-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $this->clientId,
      'status_id' => Status::ORDER_FINISHED,
      'created_at' => now()->subDays(2),
      'updated_at' => now(),
    ], $attributes));
  }

  private function dailyIds(): array
  {
    return Order::query()->daily()->pluck('id')->all();
  }

  public function test_finished_order_delivered_today_with_yesterday_slot_is_visible(): void
  {
    $id = $this->insertOrder([
      'slot_start_date' => now()->subDay()->setTime(17, 0),
      'slot_end_date' => now()->subDay()->setTime(18, 0),
      'finished_at' => now(), // доставлен сегодня
    ]);

    $this->assertContains($id, $this->dailyIds());
  }

  public function test_finished_order_with_today_slot_is_visible(): void
  {
    $id = $this->insertOrder([
      'slot_start_date' => now()->setTime(10, 0),
      'slot_end_date' => now()->setTime(11, 0),
      'finished_at' => now(),
    ]);

    $this->assertContains($id, $this->dailyIds());
  }

  public function test_finished_order_delivered_yesterday_with_yesterday_slot_is_hidden(): void
  {
    $id = $this->insertOrder([
      'slot_start_date' => now()->subDay()->setTime(17, 0),
      'slot_end_date' => now()->subDay()->setTime(18, 0),
      'finished_at' => now()->subDay()->setTime(17, 30), // доставлен вчера
      'updated_at' => now()->subDay()->setTime(17, 30),
    ]);

    $this->assertNotContains($id, $this->dailyIds());
  }

  /**
   * Заказ, доставленный позавчера, у которого сегодня изменился только updated_at
   * (ручная отметка оплаты, правка в админке, sync), НЕ должен возвращаться
   * в выдачу сборщика/курьера. Признак «доставлен сегодня» — finished_at, не updated_at.
   */
  public function test_finished_order_delivered_two_days_ago_but_updated_today_is_hidden(): void
  {
    $id = $this->insertOrder([
      'slot_start_date' => now()->subDays(2)->setTime(16, 0),
      'slot_end_date' => now()->subDays(2)->setTime(16, 40),
      'finished_at' => now()->subDays(2)->setTime(17, 47), // доставлен позавчера
      'updated_at' => now(), // сегодня отметили оплату наличными
    ]);

    $this->assertNotContains($id, $this->dailyIds());
  }

  public function test_created_order_with_future_slot_after_today_is_hidden(): void
  {
    $id = $this->insertOrder([
      'status_id' => Status::ORDER_CREATED,
      'slot_start_date' => now()->addDays(2)->setTime(10, 0),
      'slot_end_date' => now()->addDays(2)->setTime(11, 0),
    ]);

    $this->assertNotContains($id, $this->dailyIds());
  }

  /**
   * B2B-магазин: заказы на будущие даты видны сборщику заранее (ALADDIN-13553).
   */
  public function test_created_b2b_order_with_future_slot_is_visible(): void
  {
    $this->b2bShopId ??= $this->insertShop(true);

    $id = $this->insertOrder([
      'shop_id' => $this->b2bShopId,
      'status_id' => Status::ORDER_CREATED,
      'slot_start_date' => now()->addDays(2)->setTime(10, 0),
      'slot_end_date' => now()->addDays(2)->setTime(11, 0),
    ]);

    $this->assertContains($id, $this->dailyIds());
  }

  public function test_created_b2b_order_with_today_slot_is_visible(): void
  {
    $this->b2bShopId ??= $this->insertShop(true);

    $id = $this->insertOrder([
      'shop_id' => $this->b2bShopId,
      'status_id' => Status::ORDER_CREATED,
      'slot_start_date' => now()->setTime(10, 0),
      'slot_end_date' => now()->setTime(11, 0),
    ]);

    $this->assertContains($id, $this->dailyIds());
  }

  public function test_in_delivery_order_is_always_visible(): void
  {
    $id = $this->insertOrder([
      'status_id' => Status::ORDER_IN_DELIVERY,
      'slot_start_date' => now()->subDays(2)->setTime(10, 0),
      'slot_end_date' => now()->subDays(2)->setTime(11, 0),
      'updated_at' => now()->subDays(2),
    ]);

    $this->assertContains($id, $this->dailyIds());
  }
}
