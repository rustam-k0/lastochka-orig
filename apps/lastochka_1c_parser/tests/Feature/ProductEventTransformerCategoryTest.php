<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Database\Eloquent\Model;

/**
 * Регрессия: LASTOCHKI-JAMI-10114 / Phase 2 Task 2.2.
 *
 * Раньше product event'ы из 1c_parser несли:
 *   - `category_id` (1С GUID категории)
 *   - `category_source` ('1c')
 * и storefront использовал их для linkCategory к source='1c' Category.
 *
 * Теперь категории из 1С едут отдельным справочником в admin_back через
 * routing_key_prefix `category_1c.*`. Продукты больше не несут category_id/
 * category_source — вместо них только `category_1c_external_id`, чтобы admin_back
 * мог сматчить продукт с уже существующей 1С-категорией. После того как admin
 * привяжет 1С-категорию к showcase-категории, admin_back опубликует
 * product.updated с настоящими showcase `category_id` + `category_source='app'`.
 *
 * Storefront `linkCategory()` уже игнорирует payload без category_id/category_source —
 * поэтому удаление этих полей безопасно сразу после деплоя.
 */
beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');
});

/**
 * Subclass с заглушками для protected-helper'ов, которые в production обращаются
 * к реляциям/БД. Изолируем тест на чистой логике toEvent (поля payload).
 */
function buildIsolatedTransformer(): ProductEventTransformer
{
    return new class extends ProductEventTransformer
    {
        protected function getPrice(Product $product): float { return 0.0; }
        protected function getDiscountPrice(Product $product): ?float { return null; }
        protected function getTotalStock(Product $product): float { return 0.0; }
        protected function getMainImageUrl(Product $product): ?string { return null; }
        protected function getUnitType(Product $product): string { return 'piece'; }
        protected function getMeasurementUnit(Product $product): string { return 'piece'; }
        protected function getAttribute(Product $product, string $key): mixed { return null; }

        public function resolveShopCode(?Model $relatedModel = null, ?Model $product = null): string
        {
            return 'aladdin';
        }
    };
}

test('toEvent payload omits category_id and category_source', function () {
    $category = new Category();
    $category->external_id = 'cat-guid-aaaa';

    $product = new Product([
        'external_id' => 'prod-guid-bbbb',
        'name' => 'Test product',
        'is_active' => true,
    ]);
    $product->setRelation('category', $category);

    $payload = buildIsolatedTransformer()->toEvent($product);

    expect($payload)
        ->not->toHaveKey('category_id')
        ->not->toHaveKey('category_source');
});

test('toEvent payload includes category_1c_external_id from category relation', function () {
    $category = new Category();
    $category->external_id = 'cat-guid-aaaa';

    $product = new Product([
        'external_id' => 'prod-guid-bbbb',
        'name' => 'Test product',
        'is_active' => true,
    ]);
    $product->setRelation('category', $category);

    $payload = buildIsolatedTransformer()->toEvent($product);

    expect($payload)->toHaveKey('category_1c_external_id', 'cat-guid-aaaa');
});

test('toEvent payload sets category_1c_external_id to null when product has no category', function () {
    $product = new Product([
        'external_id' => 'prod-no-cat',
        'name' => 'Без категории',
        'is_active' => true,
    ]);
    $product->setRelation('category', null);

    $payload = buildIsolatedTransformer()->toEvent($product);

    expect($payload)
        ->toHaveKey('category_1c_external_id', null)
        ->not->toHaveKey('category_id')
        ->not->toHaveKey('category_source');
});

test('toEvent payload includes product code', function () {
    $product = new Product([
        'external_id' => 'prod-guid-code',
        'name' => 'Товар с кодом',
        'sku' => 'ART-1',
        'code' => '00042',
        'is_active' => true,
    ]);
    $product->setRelation('category', null);

    $payload = buildIsolatedTransformer()->toEvent($product);

    expect($payload)->toHaveKey('code', '00042');
});

test('toEvent payload sets code to null when product has no code', function () {
    $product = new Product([
        'external_id' => 'prod-guid-nocode',
        'name' => 'Товар без кода',
        'sku' => 'ART-2',
        'is_active' => true,
    ]);
    $product->setRelation('category', null);

    $payload = buildIsolatedTransformer()->toEvent($product);

    expect($payload)->toHaveKey('code', null);
});
