<?php

namespace Tests\Feature\Transformers;

use App\Models\Product;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SKU-collision protection в ProductEventTransformer.
 *
 * Контекст: 1С-источник содержит группы номенклатуры с одинаковым `articul`
 * (один sku → много external_id). Рядом живут retailbridge / ручные карточки
 * с тем же articul. Если findExistingEntity слепит их по articul — получим
 * bind разных external_id на одну admin-карточку и потерю данных
 * (см. memory/1c-integration.md, секция «дубликатные sku в 1С»).
 *
 * После фикса:
 *  - findExistingEntity всегда возвращает null (идентичность определяется
 *    через event_sync_metadata в EventConsumer ДО этого вызова);
 *  - beforeCreate генерирует синтетический articul «1C-{sku}-{external_id_short}»
 *    если sku уже занят В ТОМ ЖЕ магазине (DB-инвариант UNIQUE(shop_id,
 *    articul)). Тот же sku в другом магазине — штатная мультимагазинная
 *    выгрузка, оригинальный articul сохраняется без синтетики и без
 *    counter-перебора (иначе полная выгрузка сети из N магазинов давала
 *    квадратичное число exists()-запросов и мусорные артикулы всей базе);
 *  - beforeCreate резолвит shop_id ДО insert'а и кладёт его в атрибуты,
 *    чтобы UNIQUE(shop_id, articul) действовал атомарно в самом INSERT.
 */
class ProductSkuCollisionTest extends TestCase
{
    use DatabaseTransactions;

    private ProductEventTransformer $transformer;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ProductEventTransformer();
        // Код магазина уникален на каждый тест: resolveShopId кэширует
        // code→id статически, повторный code словил бы stale id после
        // отката DatabaseTransactions.
        $this->shop = Shop::query()->create([
            'title' => 'SKU-collision shop',
            'code' => 'SKUC_' . Str::random(8),
        ]);
    }

    private function createProduct(array $attrs): Product
    {
        return Product::query()->create(array_merge([
            'title' => $attrs['title'] ?? 'fixture',
            'shop_id' => $this->shop->id,
        ], $attrs));
    }

    private function baseEvent(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'test-event-id',
            'id' => 'aabbccdd-1111-2222-3333-444444444444',
            'shop_code' => $this->shop->code,
            'source' => '1c',
            'sku' => 'SKU-X',
            'title' => 'Тестовый товар',
            'price' => 100,
            'unit_type' => 'piece',
        ], $overrides);
    }

    public function test_findExistingEntity_returns_null_when_articul_taken_by_other_product(): void
    {
        $this->createProduct(['articul' => 'SKU-X', 'title' => 'Чужая карточка']);

        $found = $this->transformer->findExistingEntity($this->baseEvent(), []);

        $this->assertNull(
            $found,
            'findExistingEntity не должен binding на чужой articul — sku не уникальный ключ.'
        );
    }

    public function test_findExistingEntity_returns_null_when_no_articul_match(): void
    {
        $this->createProduct(['articul' => 'OTHER-SKU', 'title' => 'Другой товар']);

        $found = $this->transformer->findExistingEntity($this->baseEvent(), []);

        $this->assertNull($found);
    }

    public function test_findExistingEntity_returns_null_when_sku_field_missing(): void
    {
        $found = $this->transformer->findExistingEntity(
            $this->baseEvent(['sku' => null]),
            []
        );

        $this->assertNull($found);
    }

    public function test_findExistingEntity_returns_null_even_when_existing_is_soft_deleted(): void
    {
        $product = $this->createProduct(['articul' => 'SKU-X', 'title' => 'Удалённая']);
        $product->delete();

        $found = $this->transformer->findExistingEntity($this->baseEvent(), []);

        $this->assertNull(
            $found,
            'Воскрешение soft-deleted по совпадению sku отключено — слишком рискованно при дубликатах.'
        );
    }

    public function test_beforeCreate_preserves_articul_when_sku_free(): void
    {
        $event = $this->baseEvent();
        $transformed = ['articul' => 'SKU-X', 'title' => 'Новый товар'];

        $result = $this->transformer->beforeCreate($event, $transformed);

        $this->assertSame('SKU-X', $result['articul']);
    }

    public function test_beforeCreate_puts_resolved_shop_id_into_insert_attributes(): void
    {
        $result = $this->transformer->beforeCreate(
            $this->baseEvent(),
            ['articul' => 'SKU-X', 'title' => 'Новый товар']
        );

        $this->assertSame(
            $this->shop->id,
            $result['shop_id'],
            'shop_id должен резолвиться до INSERT: он участвует в UNIQUE(shop_id, articul).'
        );
    }

    public function test_beforeCreate_assigns_synthetic_articul_on_same_shop_collision(): void
    {
        $this->createProduct(['articul' => 'SKU-X', 'title' => 'Чужая']);

        $event = $this->baseEvent();
        $transformed = ['articul' => 'SKU-X', 'title' => 'Новая 1С позиция'];

        $result = $this->transformer->beforeCreate($event, $transformed);

        $this->assertNotSame('SKU-X', $result['articul']);
        $this->assertSame('1C-SKU-X-aabbccdd', $result['articul']);
    }

    public function test_beforeCreate_keeps_original_articul_when_sku_taken_only_in_other_shop(): void
    {
        $otherShop = Shop::query()->create([
            'title' => 'Другой магазин сети',
            'code' => 'SKUC_' . Str::random(8),
        ]);
        $this->createProduct([
            'articul' => 'SKU-X',
            'title' => 'Тот же товар в другом магазине',
            'shop_id' => $otherShop->id,
        ]);

        $result = $this->transformer->beforeCreate(
            $this->baseEvent(),
            ['articul' => 'SKU-X', 'title' => 'Копия товара для нашего магазина']
        );

        $this->assertSame(
            'SKU-X',
            $result['articul'],
            'Кросс-магазинное совпадение sku — не коллизия: UNIQUE действует в пределах магазина, '
            . 'оригинальный articul сохраняется без синтетического суффикса.'
        );
    }

    public function test_beforeCreate_handles_existing_synthetic_collision_with_counter(): void
    {
        $this->createProduct(['articul' => 'SKU-X', 'title' => 'Original']);
        $this->createProduct(['articul' => '1C-SKU-X-aabbccdd', 'title' => 'Существующий синтетический']);

        $result = $this->transformer->beforeCreate(
            $this->baseEvent(),
            ['articul' => 'SKU-X', 'title' => 'Ещё одна 1С позиция с тем же sku']
        );

        $this->assertSame('1C-SKU-X-aabbccdd-1', $result['articul']);
    }

    public function test_beforeCreate_returns_unchanged_when_articul_field_empty(): void
    {
        $transformed = ['title' => 'Без articul'];

        $result = $this->transformer->beforeCreate($this->baseEvent(['sku' => null]), $transformed);

        $this->assertArrayNotHasKey('articul', $result);
    }

    public function test_beforeCreate_returns_unchanged_when_external_id_missing(): void
    {
        $this->createProduct(['articul' => 'SKU-X']);

        $event = $this->baseEvent(['id' => null]);
        $result = $this->transformer->beforeCreate($event, ['articul' => 'SKU-X']);

        $this->assertSame(
            'SKU-X',
            $result['articul'],
            'Без external_id невозможно сгенерировать уникальный articul, защита не применяется.'
        );
    }

    public function test_beforeUpdate_strips_articul_to_protect_synthetic(): void
    {
        $product = $this->createProduct(['articul' => '1C-SKU-X-aabbccdd', 'title' => 'Existing']);

        $transformed = ['articul' => 'SKU-X', 'title' => 'Updated', 'description' => 'new desc'];
        $result = $this->transformer->beforeUpdate($product, $this->baseEvent(), $transformed);

        $this->assertArrayNotHasKey(
            'articul',
            $result,
            'beforeUpdate должен убрать articul чтобы synthetic не был перезаписан исходным sku.'
        );
        $this->assertSame('Updated', $result['title']);
        $this->assertSame('new desc', $result['description']);
    }

    public function test_beforeUpdate_preserves_other_fields(): void
    {
        $product = $this->createProduct(['articul' => 'SKU-X']);

        $transformed = ['title' => 'New Title', 'weight' => 100];
        $result = $this->transformer->beforeUpdate($product, $this->baseEvent(), $transformed);

        $this->assertSame('New Title', $result['title']);
        $this->assertSame(100, $result['weight']);
    }
}
