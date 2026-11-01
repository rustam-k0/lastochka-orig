<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Product;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductEventTransformerAdditionalInfoTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): Shop
    {
        return Shop::create([
            'title' => 'TS ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    public function test_to_event_includes_additional_info(): void
    {
        $shop = $this->makeShop();
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'additional_info' => 'Гарантия производителя 12 месяцев',
        ]);

        $payload = app(ProductEventTransformer::class)->toEvent($product->fresh());

        $this->assertArrayHasKey('additional_info', $payload);
        $this->assertSame('Гарантия производителя 12 месяцев', $payload['additional_info']);
    }

    public function test_to_event_includes_additional_info_null_by_default(): void
    {
        $shop = $this->makeShop();
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
        ]);

        $payload = app(ProductEventTransformer::class)->toEvent($product->fresh());

        $this->assertArrayHasKey(
            'additional_info',
            $payload,
            'ключ обязан присутствовать и при null — сброс значения должен доезжать до storefront'
        );
        $this->assertNull($payload['additional_info']);
    }
}
