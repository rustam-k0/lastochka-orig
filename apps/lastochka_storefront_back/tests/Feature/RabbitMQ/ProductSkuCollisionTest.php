<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SKU-collision protection в storefront ProductEventTransformer.
 *
 * Контекст: storefront получает product events из admin_back и из 1c_parser
 * напрямую (через app_events exchange). Sku из 1с-источника не уникален
 * (~2867 групп дубликатов). До фикса findExistingEntity склеивал
 * разные external_id'ы в одну storefront-карточку через
 * `where('store_id', $storeId)->where('sku', $sku)`. Симптом — на dev
 * 7 901 storefront-meta source='1c' указывали на product чей title
 * не соответствовал name из djami_1c.products.
 *
 * Фикс зеркалит admin_back (lastochka_admin_back commit 56de75e):
 * - findExistingEntity всегда null с warn-логом при коллизии
 * - beforeCreate генерирует синтетический sku "1C-{sku}-{external_id_short}"
 *   per store_id если оригинальный занят
 */
final class ProductSkuCollisionTest extends TestCase
{
    use RefreshDatabase;

    private ProductEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ProductEventTransformer();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseEvent(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'test-event-id',
            'id' => 'aabbccdd-1111-2222-3333-444444444444',
            'shop_code' => 'STORE_TEST',
            'source' => '1c',
            'sku' => 'SKU-X',
            'title' => 'Тестовый товар',
            'price' => 100,
            'unit_type' => 'piece',
        ], $overrides);
    }

    public function test_findExistingEntity_returns_null_when_sku_taken_in_same_store(): void
    {
        $store = Store::factory()->create(['code' => 'STORE_TEST']);
        Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'SKU-X',
            'title' => 'Чужая карточка',
        ]);

        $found = $this->transformer->findExistingEntity($this->baseEvent(), []);

        $this->assertNull($found, 'Sku не уникален — нельзя binding на чужую карточку.');
    }

    public function test_findExistingEntity_returns_null_when_sku_taken_in_different_store(): void
    {
        $store = Store::factory()->create(['code' => 'STORE_TEST']);
        $otherStore = Store::factory()->create(['code' => 'OTHER_STORE']);
        Product::factory()->create([
            'store_id' => $otherStore->id,
            'sku' => 'SKU-X',
            'title' => 'Карточка другого магазина',
        ]);

        $found = $this->transformer->findExistingEntity($this->baseEvent(), []);

        $this->assertNull($found);
    }

    public function test_findExistingEntity_returns_null_when_sku_missing(): void
    {
        Store::factory()->create(['code' => 'STORE_TEST']);

        $found = $this->transformer->findExistingEntity(
            $this->baseEvent(['sku' => null]),
            []
        );

        $this->assertNull($found);
    }

    public function test_findExistingEntity_returns_null_even_when_existing_is_soft_deleted(): void
    {
        $store = Store::factory()->create(['code' => 'STORE_TEST']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'SKU-X',
        ]);
        $product->delete();

        $found = $this->transformer->findExistingEntity($this->baseEvent(), []);

        $this->assertNull($found);
    }

    public function test_beforeCreate_preserves_sku_when_free(): void
    {
        $store = Store::factory()->create(['code' => 'STORE_TEST']);

        $event = $this->baseEvent();
        $transformed = ['store_id' => $store->id, 'sku' => 'SKU-X', 'title' => 'Новый'];

        $result = $this->transformer->beforeCreate($event, $transformed);

        $this->assertSame('SKU-X', $result['sku']);
    }

    public function test_beforeCreate_assigns_synthetic_sku_on_collision(): void
    {
        $store = Store::factory()->create(['code' => 'STORE_TEST']);
        Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'SKU-X',
            'title' => 'Чужая карточка',
        ]);

        $event = $this->baseEvent();
        $transformed = ['store_id' => $store->id, 'sku' => 'SKU-X', 'title' => 'Новая 1С позиция'];

        $result = $this->transformer->beforeCreate($event, $transformed);

        $this->assertSame('1C-SKU-X-aabbccdd', $result['sku']);
    }

    public function test_beforeCreate_handles_existing_synthetic_collision_with_counter(): void
    {
        $store = Store::factory()->create(['code' => 'STORE_TEST']);
        Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'SKU-X',
            'title' => 'Original',
        ]);
        Product::factory()->create([
            'store_id' => $store->id,
            'sku' => '1C-SKU-X-aabbccdd',
            'title' => 'Существующий синтетический',
        ]);

        $result = $this->transformer->beforeCreate(
            $this->baseEvent(),
            ['store_id' => $store->id, 'sku' => 'SKU-X', 'title' => 'Ещё одна'],
        );

        $this->assertSame('1C-SKU-X-aabbccdd-1', $result['sku']);
    }

    public function test_beforeCreate_uniqueness_scoped_to_store(): void
    {
        $store = Store::factory()->create(['code' => 'STORE_TEST']);
        $otherStore = Store::factory()->create(['code' => 'OTHER_STORE']);
        Product::factory()->create([
            'store_id' => $otherStore->id,
            'sku' => 'SKU-X',
            'title' => 'Карточка другого магазина',
        ]);

        $event = $this->baseEvent();
        $transformed = ['store_id' => $store->id, 'sku' => 'SKU-X', 'title' => 'Новая в STORE_TEST'];

        $result = $this->transformer->beforeCreate($event, $transformed);

        $this->assertSame(
            'SKU-X',
            $result['sku'],
            'Sku свободен в STORE_TEST → не нужно префиксить (uniqueness per store).'
        );
    }

    public function test_beforeCreate_returns_unchanged_when_external_id_missing(): void
    {
        $store = Store::factory()->create(['code' => 'STORE_TEST']);
        Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'SKU-X',
        ]);

        $event = $this->baseEvent(['id' => null]);
        $result = $this->transformer->beforeCreate(
            $event,
            ['store_id' => $store->id, 'sku' => 'SKU-X'],
        );

        $this->assertSame('SKU-X', $result['sku']);
    }

    public function test_beforeCreate_returns_null_when_store_unresolved(): void
    {
        $event = $this->baseEvent();
        $transformed = ['store_id' => null, 'sku' => 'SKU-X'];

        $result = $this->transformer->beforeCreate($event, $transformed);

        $this->assertNull($result, 'Без store_id beforeCreate должен сигнализировать пропуск (existing behaviour).');
    }

    public function test_beforeUpdate_strips_sku_to_protect_synthetic(): void
    {
        $store = Store::factory()->create(['code' => 'STORE_TEST']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => '1C-SKU-X-aabbccdd',
            'title' => 'Existing',
        ]);

        $transformed = ['sku' => 'SKU-X', 'title' => 'Updated', 'price' => 200];
        $result = $this->transformer->beforeUpdate($product, $this->baseEvent(), $transformed);

        $this->assertArrayNotHasKey(
            'sku',
            $result,
            'beforeUpdate должен убрать sku чтобы synthetic не был перезаписан исходным sku.'
        );
        $this->assertSame('Updated', $result['title']);
        $this->assertSame(200, $result['price']);
    }
}
