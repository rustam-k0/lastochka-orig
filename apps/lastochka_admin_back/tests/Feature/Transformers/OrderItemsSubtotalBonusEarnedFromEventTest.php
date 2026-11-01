<?php

namespace Tests\Feature\Transformers;

use App\Transformers\OrderEventTransformer;
use Tests\TestCase;

/**
 * MEN-IN-DEV-11267: items_subtotal (сумма товаров до скидки) и bonus_earned
 * (начисленные бонусы) уже публикуются сторфронтом в payload заказа, но
 * fromEvent() их отбрасывал — та же картина, что была с promocode.code
 * (MEN-IN-DEV-11276).
 */
class OrderItemsSubtotalBonusEarnedFromEventTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => '601',
            'shop_code' => 'mendev',
            'status' => 'created',
            'payment_type' => 'card',
            'shipping_method' => 'courier',
            'grand_total' => 900,
            'items_total_after_discount' => 900,
            'discount_total' => 0,
            'is_paid' => false,
            'slot_start_date' => '2026-07-04T16:00:00+03:00',
            'slot_end_date' => '2026-07-04T17:00:00+03:00',
            'client_comment' => null,
        ], $overrides);
    }

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

    public function test_items_subtotal_extracted_from_event(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload([
            'items_subtotal' => 1000,
        ]));

        $this->assertSame(1000, $attributes['items_subtotal']);
    }

    public function test_items_subtotal_preserves_kopecks(): void
    {
        // IMP-1: items_subtotal — деньги (decimal 15,2), а не целое число.
        // Ранее fromEvent() кастовал через (int), обрезая копейки.
        $attributes = $this->makeTransformer()->fromEvent($this->payload([
            'items_subtotal' => 1234.56,
        ]));

        $this->assertEquals(1234.56, (float) $attributes['items_subtotal']);
    }

    public function test_items_subtotal_absent_does_not_set_attribute(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload());

        $this->assertArrayNotHasKey('items_subtotal', $attributes);
    }

    public function test_bonus_earned_extracted_from_event(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload([
            'bonus_earned' => 45,
        ]));

        $this->assertSame(45, $attributes['bonus_earned']);
    }

    public function test_bonus_earned_absent_does_not_set_attribute(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload());

        $this->assertArrayNotHasKey('bonus_earned', $attributes);
    }

    public function test_bonus_spent_regression_still_works(): void
    {
        $attributes = $this->makeTransformer()->fromEvent($this->payload([
            'bonus_spent' => 30,
        ]));

        $this->assertSame(30, $attributes['bonus_spent']);
    }
}
