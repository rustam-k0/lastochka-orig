<?php

namespace Tests\Feature\Transformers;

use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Tests\TestCase;

/**
 * Регрессия ALADDIN-10680.
 *
 * Storefront присылает `shipping_method` в собственном перечислении
 * (`courier`, `pickup`, `cdek`, `russian_post`, `yandex_delivery`),
 * а в админ-БД колонка orders.shipping_method — это ENUM с другим набором:
 * (`own_courier`, `cdek`, `russian_post`). До фикса трансформер копировал
 * storefront-значение 1-в-1 → MySQL 1265 "Data truncated" → событие
 * 3 раза в DLQ → заказ не создавался в админке (сборщик его не видел).
 */
class OrderShippingMethodMappingTest extends TestCase
{
    private OrderEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer();
    }

    private function payload(string $shippingMethod): array
    {
        return [
            'id' => '277',
            'shop_code' => 'aladdinmarketplace',
            'status' => 'created',
            'payment_type' => 'card',
            'shipping_method' => $shippingMethod,
            'grand_total' => 788,
            'items_total_after_discount' => 788,
            'is_paid' => false,
            'slot_start_date' => '2026-05-21T16:00:00+03:00',
            'slot_end_date' => '2026-05-21T17:00:00+03:00',
            'client_comment' => null,
        ];
    }

    /**
     * Замена protected-helpers, которые трогают БД, на детерминированные стабы.
     */
    private function makeTransformer(): OrderEventTransformer
    {
        return new class extends OrderEventTransformer {
            protected function resolveShopId(string $shopCode): int
            {
                return 3;
            }

            protected function mapStatusFromExternal(string $status): int
            {
                return 1;
            }

            protected function mapPaymentTypeFromExternal(string $paymentType): int
            {
                return 1;
            }
        };
    }

    public function test_courier_maps_to_own_courier_enum(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload('courier'));

        $this->assertSame('own_courier', $attributes['shipping_method']);
        $this->assertSame(Type::DELIVERY_COURIER, $attributes['type_id']);
    }

    public function test_pickup_maps_to_own_courier_enum(): void
    {
        // pickup — самовывоз: внешней доставки нет, в admin-ENUM кладём own_courier
        // (тип доставки различается через type_id = DELIVERY_PICKUP).
        $attributes = $this->makeTransformer()->fromEvent($this->payload('pickup'));

        $this->assertSame('own_courier', $attributes['shipping_method']);
        $this->assertSame(Type::DELIVERY_PICKUP, $attributes['type_id']);
    }

    public function test_cdek_passes_through(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload('cdek'));

        $this->assertSame('cdek', $attributes['shipping_method']);
        $this->assertSame(Type::DELIVERY_CDEK, $attributes['type_id']);
    }

    public function test_russian_post_passes_through(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload('russian_post'));

        $this->assertSame('russian_post', $attributes['shipping_method']);
        $this->assertSame(Type::DELIVERY_RUSSIAN_POST, $attributes['type_id']);
    }

    public function test_yandex_delivery_maps_to_own_courier_enum(): void
    {
        // Яндекс.Доставка интегрирована через нашего курьера-партнёра,
        // отдельного значения в admin-ENUM нет.
        $attributes = $this->makeTransformer()->fromEvent($this->payload('yandex_delivery'));

        $this->assertSame('own_courier', $attributes['shipping_method']);
        $this->assertSame(Type::DELIVERY_YANDEX, $attributes['type_id']);
    }

    public function test_unknown_shipping_method_falls_back_to_own_courier(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload('something-weird'));

        $this->assertSame('own_courier', $attributes['shipping_method']);
    }

    public function test_shipping_method_absent_in_payload_is_not_written(): void
    {
        // Если поля нет в payload — атрибут не должен попасть в массив
        // (БД сохранит дефолт ENUM = own_courier).
        $payload = $this->payload('courier');
        unset($payload['shipping_method']);

        $attributes = $this->makeTransformer()->fromEvent($payload);

        $this->assertArrayNotHasKey('shipping_method', $attributes);
    }
}
