<?php

use App\Models\Order;
use App\RabbitMQ\DTO\OrderEventDTO;
use App\Services\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Заказы импортируются с привязкой к shop_id из 1С-конфига (orders.shop_id NOT NULL).
    makeIntegrationConfig();
    $this->service = new OrderImportService();
});

/**
 * Реальный (плоский) контракт события заказа от курьерки.
 */
function makeValidImportMessage(): array
{
    return [
        'event' => 'order.created',
        'event_id' => 'evt-1',
        'source' => 'aladdin',
        'shop_code' => 'aladdin',
        'timestamp' => '2025-01-09T15:30:00+03:00',
        'id' => 12345,
        'status' => 'assembled',
        'client_info' => [
            'name' => 'Иван',
            'surname' => 'Иванов',
            'phone' => '+79001234567',
            'email' => 'ivan@example.com',
        ],
        'delivery_address' => 'г. Москва, ул. Примерная, д. 1',
        'client_comment' => 'Позвонить за час до доставки',
        'grand_total' => 15000.00,
        'items' => [
            [
                'id' => '1',
                'source' => 'aladdin',
                'product_id' => 'product-uuid-1',
                'product_source' => 'aladdin',
                'product_name' => 'Товар 1',
                'quantity' => 2,
                'unit_price' => 5000.00,
                'unit_price_effective' => 5000.00,
                'line_total' => 10000.00,
                'item_status' => 'confirmed',
            ],
            [
                'id' => '2',
                'source' => 'aladdin',
                'product_id' => 'product-uuid-2',
                'product_source' => 'aladdin',
                'product_name' => 'Товар 2',
                'quantity' => 1,
                'unit_price' => 5000.00,
                'unit_price_effective' => 5000.00,
                'line_total' => 5000.00,
                'item_status' => 'confirmed',
            ],
        ],
    ];
}

test('импортирует заказ из валидного сообщения с корректным составом', function () {
    $product = makeProduct(['external_id' => 'product-uuid-1']);

    $dto = OrderEventDTO::fromMessage(makeValidImportMessage());
    $order = $this->service->import($dto);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'shop_order_id' => 12345,
        'number' => '12345',
        'status' => Order::STATUS_NEW,
        'source' => Order::SOURCE_SHOP,
        'customer_name' => 'Иван',
        'customer_phone' => '+79001234567',
        'customer_email' => 'ivan@example.com',
    ]);

    $this->assertDatabaseHas('order_items', [
        'order_id' => $order->id,
        'external_id' => 'product-uuid-1',
        'product_id' => $product->id,
        'name' => 'Товар 1',
        'quantity' => 2,
        'price' => 5000.00,
        'total' => 10000.00,
    ]);
});

test('идемпотентно обрабатывает дубликат заказа', function () {
    $existingOrder = makeOrder(['shop_order_id' => 12345]);

    $dto = OrderEventDTO::fromMessage(makeValidImportMessage());
    $order = $this->service->import($dto);

    expect($order->id)->toBe($existingOrder->id);
    $this->assertDatabaseCount('orders', 1);
});

test('создаёт позицию без product_id если товар не найден', function () {
    $dto = OrderEventDTO::fromMessage(makeValidImportMessage());
    $order = $this->service->import($dto);

    $this->assertDatabaseHas('order_items', [
        'order_id' => $order->id,
        'external_id' => 'product-uuid-1',
        'product_id' => null,
    ]);
});

test('валидация: id обязателен', function () {
    $message = makeValidImportMessage();
    unset($message['id']);

    expect(fn () => OrderEventDTO::fromMessage($message))
        ->toThrow(InvalidArgumentException::class, 'id обязателен');
});

test('валидация: минимум одна позиция', function () {
    $message = makeValidImportMessage();
    $message['items'] = [];

    expect(fn () => OrderEventDTO::fromMessage($message))
        ->toThrow(InvalidArgumentException::class, 'items должен содержать минимум 1 позицию');
});

test('валидация: phone или email обязателен', function () {
    $message = makeValidImportMessage();
    unset($message['client_info']['phone']);
    unset($message['client_info']['email']);

    expect(fn () => OrderEventDTO::fromMessage($message))
        ->toThrow(InvalidArgumentException::class, 'phone или email');
});

test('валидация: product_id в позиции обязателен', function () {
    $message = makeValidImportMessage();
    unset($message['items'][0]['product_id']);

    expect(fn () => OrderEventDTO::fromMessage($message))
        ->toThrow(InvalidArgumentException::class, 'product_id обязателен');
});

test('isDuplicate true для существующего заказа', function () {
    makeOrder(['shop_order_id' => 999]);

    expect($this->service->isDuplicate(999))->toBeTrue();
});

test('isDuplicate false для нового заказа', function () {
    expect($this->service->isDuplicate(999))->toBeFalse();
});
