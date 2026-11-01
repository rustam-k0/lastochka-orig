<?php

use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->transformer = new ProductEventTransformer();
});

/**
 * Изолированный transformer со стабами DB-зависимых protected-методов —
 * используется только тестами на toEvent(), которым не нужна БД (prices/stocks/images).
 * По образцу tests/Feature/ProductEventTransformerCategoryTest.php::buildIsolatedTransformer().
 */
function buildIsolatedProductEventTransformer(): ProductEventTransformer
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

        public function resolveShopCode(?object $relatedModel = null, ?object $product = null): string
        {
            return config('rabbitmq-events.shop_code', 'aladdin');
        }
    };
}

test('toCustomEvent returns correct payload for price_updated event', function () {
    $product = new Product([
        'id' => 1,
        'external_id' => 'test-external-id-123',
        'name' => 'Test Product',
        'sku' => 'SKU-001',
        'is_active' => true,
    ]);

    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');

    $customPayload = [
        'price' => 1500.00,
        'old_price' => 1400.00,
        'price_type' => 'Розничная',
        'currency' => 'RUB',
        'discount_price' => 1200.00,
    ];

    $eventId = 'test-event-id-456';

    $result = $this->transformer->toCustomEvent($product, 'price_updated', $customPayload, $eventId);

    expect($result)->toBeArray();
    expect($result['event_id'])->toBe($eventId);
    expect($result['event_type'])->toBe('price_updated');
    expect($result['id'])->toBe('test-external-id-123');
    expect($result['shop_code'])->toBe('aladdin');
    expect($result['source'])->toBe('1c');
    expect($result['sku'])->toBe('SKU-001');
    expect($result['price'])->toBe(1500.00);
    expect($result['old_price'])->toBe(1400.00);
    expect($result['price_type'])->toBe('Розничная');
    expect($result['currency'])->toBe('RUB');
    expect($result['discount_price'])->toBe(1200.00);
    expect($result)->toHaveKey('event_timestamp');
});

test('toCustomEvent generates uuid when eventId not provided', function () {
    $product = new Product([
        'external_id' => 'test-product',
        'name' => 'Test',
        'sku' => 'SKU-001',
    ]);

    $result = $this->transformer->toCustomEvent($product, 'price_updated', [
        'price' => 100,
        'old_price' => 90,
    ]);

    expect($result['event_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

test('toCustomEvent returns null for unknown event types', function () {
    $product = new Product([
        'external_id' => 'test-product',
        'name' => 'Test',
    ]);

    $result = $this->transformer->toCustomEvent($product, 'unknown_event', []);

    expect($result)->toBeNull();
});

test('toCustomEvent uses default values when payload is missing fields', function () {
    $product = new Product([
        'external_id' => 'test-product',
        'name' => 'Test',
        'sku' => null, // No SKU
    ]);
    // Симулируем ID для fallback SKU
    $product->id = 99;

    $result = $this->transformer->toCustomEvent($product, 'price_updated', []);

    expect($result['price'])->toBe(0);
    expect($result['old_price'])->toBeNull();
    expect($result['price_type'])->toBe('Розничная');
    expect($result['currency'])->toBe('RUB');
    expect($result['discount_price'])->toBeNull();
    expect($result['sku'])->toBe('1C-99'); // Fallback SKU
});

test('toCustomEvent returns correct payload for stock_updated event', function () {
    $product = new Product([
        'id' => 1,
        'external_id' => 'test-external-id-123',
        'name' => 'Test Product',
        'sku' => 'SKU-001',
        'is_active' => true,
    ]);

    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');

    $customPayload = [
        'warehouse_id' => 'warehouse-uuid-123',
        'warehouse_name' => 'Основной склад',
        'quantity' => 10.000,
        'old_quantity' => 5.000,
    ];

    $eventId = 'test-event-id-789';

    $result = $this->transformer->toCustomEvent($product, 'stock_updated', $customPayload, $eventId);

    expect($result)->toBeArray();
    expect($result['event_id'])->toBe($eventId);
    expect($result['event_type'])->toBe('stock_updated');
    expect($result['id'])->toBe('test-external-id-123');
    expect($result['shop_code'])->toBe('aladdin');
    expect($result['source'])->toBe('1c');
    expect($result['sku'])->toBe('SKU-001');
    expect($result['warehouse_id'])->toBe('warehouse-uuid-123');
    expect($result['warehouse_name'])->toBe('Основной склад');
    expect($result['quantity'])->toBe(10.000);
    expect($result['old_quantity'])->toBe(5.000);
    expect($result)->toHaveKey('event_timestamp');
});

test('toCustomEvent stock_updated generates uuid when eventId not provided', function () {
    $product = new Product([
        'external_id' => 'test-product',
        'name' => 'Test',
        'sku' => 'SKU-001',
    ]);

    $result = $this->transformer->toCustomEvent($product, 'stock_updated', [
        'warehouse_id' => 'wh-1',
        'warehouse_name' => 'Склад 1',
        'quantity' => 20,
        'old_quantity' => 15,
    ]);

    expect($result['event_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

test('toCustomEvent stock_updated uses default values when payload is missing fields', function () {
    $product = new Product([
        'external_id' => 'test-product',
        'name' => 'Test',
        'sku' => null,
    ]);
    $product->id = 99;

    $result = $this->transformer->toCustomEvent($product, 'stock_updated', []);

    expect($result['warehouse_id'])->toBeNull();
    expect($result['warehouse_name'])->toBeNull();
    expect($result['quantity'])->toBe(0);
    expect($result['old_quantity'])->toBeNull();
    expect($result['sku'])->toBe('1C-99');
});

// Тест toEvent требует интеграции с БД (запрос prices)
// Перенесён в Feature тесты

test('normalizeNdsToVatCode maps 1C nds to YooKassa vat code', function (mixed $raw, ?int $code) {
    expect($this->transformer->normalizeNdsToVatCode($raw))->toBe($code);
})->with([
    // Базовая ставка РФ с 01.01.2026 (ФЗ №425-ФЗ): 22% и расчётная 22/122.
    ['22', 11],
    ['22/122', 12],
    ['Без налога', 1],
    // Прежние ставки сохранены для переходных/исторических операций.
    ['20', 4],
    ['18', 4],
    ['10', 3],
    ['0', 2],
    ['Без НДС', 1],
    ['без ндс', 1],
    ['НДС не облагается', 1],
    ['20/120', 6],
    ['10/110', 5],
    ['', null],
    ['мусор', null],
    [null, null],
]);

test('toEvent includes plu field from product', function () {
    $product = new Product([
        'external_id' => 'test-external-id-plu',
        'name' => 'Яблоки Голден',
        'sku' => 'SKU-PLU-1',
        'plu' => '4126',
    ]);

    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');

    $result = buildIsolatedProductEventTransformer()->toEvent($product);

    expect($result)->toHaveKey('plu');
    expect($result['plu'])->toBe('4126');
});

test('toEvent returns null plu when product has no PLU yet', function () {
    $product = new Product([
        'external_id' => 'test-external-id-no-plu',
        'name' => 'Товар без ПЛУ',
        'sku' => 'SKU-NO-PLU',
    ]);

    $result = buildIsolatedProductEventTransformer()->toEvent($product);

    expect($result)->toHaveKey('plu');
    expect($result['plu'])->toBeNull();
});

test('toEvent omits active for existing product', function () {
    $product = new Product([
        'external_id' => 'test-external-id-active-upd',
        'name' => 'Существующий товар',
        'sku' => 'SKU-ACT-1',
        'is_active' => true,
    ]);

    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');

    $result = buildIsolatedProductEventTransformer()->toEvent($product);

    expect($result)->not->toHaveKey('active');
});

test('toEvent includes active for newly created product', function () {
    $product = new Product([
        'external_id' => 'test-external-id-active-new',
        'name' => 'Новый товар',
        'sku' => 'SKU-ACT-2',
        'is_active' => true,
    ]);
    $product->wasRecentlyCreated = true;

    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');

    $result = buildIsolatedProductEventTransformer()->toEvent($product);

    expect($result)->toHaveKey('active');
    expect($result['active'])->toBeTrue();
});
