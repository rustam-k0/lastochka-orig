<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Product;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductEventTransformerAdultOnlyTest extends TestCase
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

    public function test_to_event_includes_adult_only_true(): void
    {
        $shop = $this->makeShop();
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'adult_only' => true,
        ]);

        $payload = app(ProductEventTransformer::class)->toEvent($product->fresh());

        $this->assertArrayHasKey('adult_only', $payload);
        $this->assertTrue($payload['adult_only']);
    }

    public function test_to_event_includes_adult_only_false_by_default(): void
    {
        $shop = $this->makeShop();
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
        ]);

        $payload = app(ProductEventTransformer::class)->toEvent($product->fresh());

        $this->assertArrayHasKey('adult_only', $payload);
        $this->assertFalse($payload['adult_only']);
    }

    public function test_from_event_maps_adult_only_when_present(): void
    {
        $attrs = app(ProductEventTransformer::class)->fromEvent([
            'event_id' => 'test-event-id',
            'id' => 'ext-1',
            'shop_code' => 'aladdinmarketplace',
            'source' => 'retailbridge',
            'sku' => '1C-99',
            'title' => 'Тестовый товар',
            'price' => 100,
            'unit_type' => 'piece',
            'adult_only' => true,
        ]);

        $this->assertArrayHasKey('adult_only', $attrs);
        $this->assertTrue($attrs['adult_only']);
    }

    public function test_from_event_omits_adult_only_when_absent(): void
    {
        $attrs = app(ProductEventTransformer::class)->fromEvent([
            'event_id' => 'test-event-id',
            'id' => 'ext-1',
            'shop_code' => 'aladdinmarketplace',
            'source' => 'retailbridge',
            'sku' => '1C-99',
            'title' => 'Тестовый товар',
            'price' => 100,
            'unit_type' => 'piece',
        ]);

        $this->assertArrayNotHasKey('adult_only', $attrs, 'партиальный апдейт без этого ключа не должен затирать существующее значение на false');
    }
}
