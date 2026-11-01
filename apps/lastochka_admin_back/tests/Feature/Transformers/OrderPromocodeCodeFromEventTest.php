<?php

namespace Tests\Feature\Transformers;

use App\Transformers\OrderEventTransformer;
use Tests\TestCase;

/**
 * MEN-IN-DEV-11276: код промокода уже приходит в payload заказа
 * (`promocode.code`, storefront OrderEventTransformer::buildPromocode),
 * но fromEvent() его отбрасывал — читал только promocode.discount_percent.
 */
class OrderPromocodeCodeFromEventTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => '501',
            'shop_code' => 'mendev',
            'status' => 'created',
            'payment_type' => 'card',
            'shipping_method' => 'courier',
            'grand_total' => 900,
            'items_total_after_discount' => 900,
            'discount_total' => 100,
            'is_paid' => false,
            'slot_start_date' => '2026-07-03T16:00:00+03:00',
            'slot_end_date' => '2026-07-03T17:00:00+03:00',
            'client_comment' => null,
        ], $overrides);
    }

    /**
     * Замена protected-helpers, которые трогают БД, на детерминированные
     * стабы — тот же паттерн, что в OrderShippingMethodMappingTest.
     */
    private function makeTransformer(): OrderEventTransformer
    {
        return new class extends OrderEventTransformer {
            protected function resolveShopId(string $shopCode): int
            {
                return 1;
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

    public function test_promocode_code_extracted_from_event(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload([
            'promocode' => ['code' => 'SUMMER10', 'discount_percent' => 10],
        ]));

        $this->assertSame('SUMMER10', $attributes['promocode_code']);
        $this->assertSame(10, $attributes['discount_percent']);
    }

    public function test_promocode_absent_does_not_set_promocode_code(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload());

        $this->assertArrayNotHasKey('promocode_code', $attributes);
    }

    public function test_promocode_present_without_code_key_does_not_set_promocode_code(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload([
            'promocode' => ['discount_percent' => 15],
        ]));

        $this->assertArrayNotHasKey('promocode_code', $attributes);
        $this->assertSame(15, $attributes['discount_percent']);
    }
}
