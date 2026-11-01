<?php

namespace Tests\Feature\Transformers;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тесты дополнений ProductEventTransformer под привязку 1С-категорий
 * (LASTOCHKI-JAMI-10114, spec §4.2.3).
 *
 * Покрывают:
 *  - toEvent: category_ids массив из pivot-категорий в порядке position
 *    (showcase_category_id удалён в Phase A, поле category_id в payload заменено на category_ids).
 *  - fromEvent: резолв category_1c_external_id → category_1c_id через
 *    locally сохранённый Category1c (shop_id + external_id).
 *  - fromEvent: array_key_exists-парадигма для category_1c_external_id —
 *    partial event без поля не должен трогать category_1c_id.
 */
class ProductEventTransformerCategory1cTest extends TestCase
{
    use DatabaseTransactions;

    private ProductEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ProductEventTransformer();
        ProductEventTransformer::clearShopCodeCache();
    }

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    /** @test */
    public function to_event_contains_category_ids_array_with_single_pivot_category(): void
    {
        $shop = $this->makeShop('p-shop-' . uniqid());
        $catA = Category::factory()->create();
        $catB = Category::factory()->create();

        $product = Product::query()->create([
            'title' => 'P1',
            'articul' => 'A1-' . uniqid(),
            'shop_id' => $shop->id,
        ]);
        // catA — position 0 (первая), catB — position 1 (вторая)
        $product->categories()->sync([
            $catA->id => ['position' => 0],
            $catB->id => ['position' => 1],
        ]);
        $product->refresh()->load('categories');

        $event = $this->transformer->toEvent($product);

        $this->assertArrayHasKey('category_ids', $event);
        $this->assertIsArray($event['category_ids']);
        $this->assertSame([(string) $catA->id, (string) $catB->id], $event['category_ids']);
        $this->assertArrayNotHasKey('category_id', $event);
    }

    /** @test */
    public function to_event_returns_empty_category_ids_when_no_pivot_categories(): void
    {
        $shop = $this->makeShop('p-shop-' . uniqid());

        $product = Product::query()->create([
            'title' => 'P2',
            'articul' => 'A2-' . uniqid(),
            'shop_id' => $shop->id,
        ]);
        // Нет pivot-категорий

        $event = $this->transformer->toEvent($product->fresh('categories'));

        $this->assertArrayHasKey('category_ids', $event);
        $this->assertSame([], $event['category_ids']);
        $this->assertNull($event['category_source']);
        $this->assertArrayNotHasKey('category_id', $event);
    }

    /** @test */
    public function from_event_resolves_category_1c_external_id_to_local_id(): void
    {
        $shop = $this->makeShop('p-shop-' . uniqid());
        $cat1c = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'ext-1c-cat-1',
        ]);

        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'shop_code' => $shop->code,
            'category_1c_external_id' => 'ext-1c-cat-1',
        ]));

        $this->assertArrayHasKey('category_1c_id', $attrs);
        $this->assertSame($cat1c->id, $attrs['category_1c_id']);
    }

    /** @test */
    public function from_event_sets_category_1c_id_null_when_payload_field_is_null(): void
    {
        $shop = $this->makeShop('p-shop-' . uniqid());

        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'shop_code' => $shop->code,
            'category_1c_external_id' => null,
        ]));

        $this->assertArrayHasKey('category_1c_id', $attrs);
        $this->assertNull($attrs['category_1c_id']);
    }

    /** @test */
    public function from_event_sets_category_1c_id_null_when_local_record_missing(): void
    {
        $shop = $this->makeShop('p-shop-' . uniqid());

        // 1С-категория ещё не приехала локально
        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'shop_code' => $shop->code,
            'category_1c_external_id' => 'never-arrived-yet',
        ]));

        $this->assertArrayHasKey('category_1c_id', $attrs);
        $this->assertNull($attrs['category_1c_id']);
    }

    /** @test */
    public function from_event_writes_pending_external_id_when_category_1c_not_yet_arrived(): void
    {
        $shop = $this->makeShop('p-shop-' . uniqid());

        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'shop_code' => $shop->code,
            'category_1c_external_id' => 'pending-uuid-1',
        ]));

        $this->assertNull($attrs['category_1c_id']);
        $this->assertSame('pending-uuid-1', $attrs['pending_category_1c_external_id']);
    }

    /** @test */
    public function from_event_clears_pending_when_category_1c_resolved(): void
    {
        $shop = $this->makeShop('p-shop-' . uniqid());
        $cat1c = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'resolved-uuid-1',
        ]);

        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'shop_code' => $shop->code,
            'category_1c_external_id' => 'resolved-uuid-1',
        ]));

        $this->assertSame($cat1c->id, $attrs['category_1c_id']);
        $this->assertNull($attrs['pending_category_1c_external_id']);
    }

    /** @test */
    public function from_event_clears_pending_when_payload_field_is_null(): void
    {
        $shop = $this->makeShop('p-shop-' . uniqid());

        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'shop_code' => $shop->code,
            'category_1c_external_id' => null,
        ]));

        $this->assertNull($attrs['category_1c_id']);
        $this->assertNull($attrs['pending_category_1c_external_id']);
    }

    /** @test */
    public function from_event_does_not_touch_category_1c_id_when_field_absent(): void
    {
        $shop = $this->makeShop('p-shop-' . uniqid());

        $attrs = $this->transformer->fromEvent($this->baseEvent([
            'shop_code' => $shop->code,
            // category_1c_external_id отсутствует — partial event
        ]));

        $this->assertArrayNotHasKey('category_1c_id', $attrs);
    }

    private function baseEvent(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'test-event-id',
            'id' => 'ext-1',
            'shop_code' => 'default',
            'source' => 'retailbridge',
            'sku' => '1C-' . uniqid(),
            'title' => 'Тестовый товар',
            'price' => 150,
            'unit_type' => 'piece',
        ], $overrides);
    }
}
