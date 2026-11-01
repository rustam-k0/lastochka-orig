<?php

namespace Tests\Feature\Aggregator;

use App\Aggregator\Transformers\OfferEventTransformer;
use App\Aggregator\Transformers\PlatformProductEventTransformer;
use App\Models\PlatformProduct;
use App\Models\PlatformProductBarcode;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тесты payload-трансформеров публикации (FOODLINK-10874, §6.7, §15.1).
 */
class AggregatorTransformersTest extends TestCase
{
    use DatabaseTransactions;

    public function test_offer_event_payload_shape(): void
    {
        $shop = Shop::create(['code' => 'dagbars', 'title' => 'Даг Барс']);
        $product = Product::withoutEvents(fn () => Product::create([
            'title' => 'Молоко', 'articul' => uniqid('a_'), 'shop_id' => $shop->id,
            'status_id' => Status::CODE_ACTIVE, 'unit_type_id' => Type::UNIT_PIECE,
        ]));
        $platform = PlatformProduct::create(['canonical_title' => 'Молоко', 'is_active' => true]);
        PlatformProductBarcode::create(['platform_product_id' => $platform->id, 'ean' => '4607034012345', 'is_primary' => true]);
        $offer = ProductOffer::create([
            'platform_product_id' => $platform->id, 'shop_id' => $shop->id,
            'local_product_id' => $product->id, 'price' => 12990, 'qty' => 47, 'min_qty' => 2, 'is_active' => true,
        ]);

        $payload = (new OfferEventTransformer())->toEvent($offer->fresh(['platformProduct.barcodes', 'shop']));

        $this->assertSame('dagbars', $payload['external_shop_code']);
        $this->assertSame($platform->id, $payload['external_platform_id']);
        $this->assertSame('4607034012345', $payload['primary_ean']);
        $this->assertSame(12990, $payload['price']);
        $this->assertEquals(47.0, $payload['qty']);
        $this->assertEquals(2.0, $payload['min_qty']);
        $this->assertTrue($payload['is_active']);
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('source', $payload);
    }

    public function test_platform_product_payload_is_product_compatible(): void
    {
        $platform = PlatformProduct::create([
            'canonical_title' => 'Молоко простоквашино 3.2%',
            'canonical_description' => 'Описание',
            'is_active' => true,
        ]);
        PlatformProductBarcode::create(['platform_product_id' => $platform->id, 'ean' => '4607034012345', 'is_primary' => true]);
        PlatformProductBarcode::create(['platform_product_id' => $platform->id, 'ean' => '4607034098765', 'is_primary' => false]);

        $payload = (new PlatformProductEventTransformer())->toEvent($platform->fresh('barcodes'));

        // Product-совместимые поля (Bagisto-консьюмер переиспользует ProductEventTransformer).
        $this->assertSame('platform-' . $platform->id, $payload['sku']);
        $this->assertSame('Молоко простоквашино 3.2%', $payload['title']);
        $this->assertSame('piece', $payload['unit_type']);
        $this->assertSame(0, $payload['price']);
        $this->assertEqualsCanonicalizing(['4607034012345', '4607034098765'], $payload['barcodes']);
        $this->assertSame('4607034012345', $payload['primary_barcode']);
        $this->assertSame($platform->id, $payload['external_platform_id']);
        $this->assertTrue($payload['active']);
    }
}
