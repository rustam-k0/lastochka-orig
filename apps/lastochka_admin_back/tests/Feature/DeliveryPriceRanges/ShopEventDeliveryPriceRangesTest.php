<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\Status;
use App\Transformers\ShopEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MID-280: событие магазина несёт ступени стоимости доставки основной зоны,
 * витрина хранит их в stores.delivery_price_ranges для расчёта без адреса.
 *
 * Флаг delivery_price_ranges (Task 8) на это событие не влияет (фикс-раунд 1):
 * событие несёт ступени независимо от состояния модуля, гейт только на чтении
 * на витрине. Поведение при выключенном модуле отдельно покрыто
 * DeliveryPriceRangesModuleTest.
 */
class ShopEventDeliveryPriceRangesTest extends TestCase
{
    use DatabaseTransactions;

    private function shop(): Shop
    {
        return Shop::create([
            'title' => 'MID-280 shop ' . uniqid(),
            'status_id' => Status::CODE_ACTIVE,
        ]);
    }

    private function zone(Shop $shop, array $attrs = []): ShopZone
    {
        return ShopZone::create(array_merge([
            'shop_id' => $shop->id,
            'name' => 'Зона ' . uniqid(),
            'delivery_price' => 200,
            'min_order_price' => 500,
            'delivery_type' => 'own',
            'is_active' => true,
        ], $attrs));
    }

    private function payload(Shop $shop): array
    {
        return (new ShopEventTransformer())->toEvent($shop->fresh());
    }

    public function test_payload_carries_sorted_ranges_of_primary_zone(): void
    {
        $shop = $this->shop();
        $this->zone($shop, [
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => [
                ['from_amount' => 3000, 'price' => 0],
                ['from_amount' => 1000, 'price' => 100],
            ],
        ]);

        $payload = $this->payload($shop);

        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $payload['delivery_price_ranges']);
        $this->assertSame(3000, (int) $payload['free_delivery_from']);
    }

    public function test_payload_skips_inactive_zone(): void
    {
        $shop = $this->shop();
        $this->zone($shop, [
            'is_active' => false,
            'delivery_price_ranges' => [['from_amount' => 500, 'price' => 50]],
        ]);
        $this->zone($shop, [
            'free_delivery_from' => 2000,
            'delivery_price_ranges' => [['from_amount' => 2000, 'price' => 0]],
        ]);

        $this->assertSame([['from_amount' => 2000, 'price' => 0]], $this->payload($shop)['delivery_price_ranges']);
    }

    public function test_payload_has_empty_ranges_for_zone_without_ranges(): void
    {
        $shop = $this->shop();
        $this->zone($shop);

        $payload = $this->payload($shop);

        $this->assertArrayHasKey('delivery_price_ranges', $payload);
        $this->assertSame([], $payload['delivery_price_ranges']);
    }

    public function test_payload_has_empty_ranges_without_active_zones(): void
    {
        $shop = $this->shop();
        $this->zone($shop, [
            'is_active' => false,
            'delivery_price_ranges' => [['from_amount' => 500, 'price' => 50]],
        ]);

        $this->assertSame([], $this->payload($shop)['delivery_price_ranges']);
        $this->assertSame([], $this->payload($this->shop())['delivery_price_ranges']);
    }
}
