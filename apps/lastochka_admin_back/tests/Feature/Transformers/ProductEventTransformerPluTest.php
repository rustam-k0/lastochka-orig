<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Product;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * PLU весового товара из 1С едет в витрину рядом с кодом: весы печатают PLU
 * в весовом штрихкоде, и витрина ищет по нему товар при сканировании.
 */
class ProductEventTransformerPluTest extends TestCase
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

    public function test_to_event_includes_plu_next_to_code(): void
    {
        $product = Product::create([
            'title' => 'Перец Болгарский Красный ' . uniqid(),
            'shop_id' => $this->makeShop()->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'code' => '52026',
            'plu' => '00030',
        ]);

        $payload = app(ProductEventTransformer::class)->toEvent($product->fresh());

        $this->assertSame('52026', $payload['code']);
        $this->assertArrayHasKey('plu', $payload);
        $this->assertSame('00030', $payload['plu']);
    }

    public function test_to_event_sends_null_plu_when_1c_has_not_sent_it(): void
    {
        $product = Product::create([
            'title' => 'Совок Этна ' . uniqid(),
            'shop_id' => $this->makeShop()->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'code' => '119360',
        ]);

        $payload = app(ProductEventTransformer::class)->toEvent($product->fresh());

        $this->assertArrayHasKey('plu', $payload);
        $this->assertNull($payload['plu']);
    }
}
