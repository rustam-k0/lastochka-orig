<?php

use App\Models\Order;
use App\Models\Product;
use App\RabbitMQ\Consumers\OrderConsumer;
use App\RabbitMQ\DTO\OrderEventDTO;
use App\Services\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpAmqpLib\Message\AMQPMessage;

uses(RefreshDatabase::class);

/**
 * Хелпер: собрать плоское событие заказа (реальный контракт курьерки).
 */
function makeOrderEvent(array $overrides = []): array
{
    return array_merge([
        'event_id' => 'evt-'.uniqid(),
        'id' => 12345,
        'status' => 'assembled',
        'source' => 'aladdin',
        'shop_code' => 'aladdin',
        'client_info' => [
            'name' => 'Иван',
            'surname' => 'Иванов',
            'phone' => '+79001234567',
            'email' => 'ivan@example.com',
        ],
        'grand_total' => 0,
        'items' => [],
    ], $overrides);
}

function makeItem(array $overrides = []): array
{
    return array_merge([
        'id' => '1',
        'source' => 'aladdin',
        'product_id' => 'guid-1',
        'product_source' => 'aladdin',
        'product_name' => 'Молоко 1л',
        'unit_price' => 100.0,
        'unit_price_effective' => 100.0,
        'line_total' => 200.0,
        'quantity' => 2,
        'item_status' => 'confirmed',
    ], $overrides);
}

// --- order.created: корректная запись состава из реального плоского контракта ---

test('import пишет название/цену/сумму из реальных ключей product_name/unit_price/line_total', function () {
    makeIntegrationConfig(); // заказ создаётся с shop_id из 1С-конфига (orders.shop_id NOT NULL)
    makeProduct(['external_id' => 'guid-1']);

    $dto = OrderEventDTO::fromMessage(makeOrderEvent([
        'items' => [makeItem(['product_name' => 'Молоко 1л', 'unit_price_effective' => 100.0, 'line_total' => 200.0, 'quantity' => 2])],
    ]));

    $order = app(OrderImportService::class)->import($dto);
    $item = $order->items()->first();

    expect($item->name)->toBe('Молоко 1л');
    expect((float) $item->price)->toBe(100.0);
    expect((float) $item->total)->toBe(200.0);
    expect((float) $item->quantity)->toBe(2.0);
});

// --- Замена товара: в 1С уходит фактически выданный, без оригинала ---

test('syncItems исключает заменённую позицию и _origin, оставляет товар-замену', function () {
    $order = makeOrder(['shop_order_id' => 500]);
    $service = app(OrderImportService::class);

    $items = [
        // оригинал-замена (запись _origin) — не выгружаем
        makeItem(['id' => '10_origin', 'product_id' => 'guid-old', 'product_name' => 'Старый товар', 'item_status' => 'replaced']),
        // заменённая исходная позиция — не выгружаем
        makeItem(['id' => '10', 'product_id' => 'guid-old', 'product_name' => 'Старый товар', 'item_status' => 'replaced', 'line_total' => 0.0]),
        // фактически выданный товар-замена — выгружаем
        makeItem(['id' => '11', 'product_id' => 'guid-new', 'product_name' => 'Новый товар', 'item_status' => 'added', 'unit_price_effective' => 150.0, 'line_total' => 150.0, 'quantity' => 1]),
    ];

    $service->syncItems($order, $items);

    $names = $order->items()->pluck('name')->all();
    expect($names)->toBe(['Новый товар']);
    expect((float) $order->fresh()->total)->toBe(150.0);
});

// --- Недовложение: уменьшение количества ---

test('syncItems обновляет количество при недовложении', function () {
    $order = makeOrder(['shop_order_id' => 501]);
    $order->items()->create(['external_id' => 'guid-1', 'name' => 'Молоко 1л', 'quantity' => 3, 'price' => 100, 'total' => 300]);
    $service = app(OrderImportService::class);

    $service->syncItems($order, [
        makeItem(['quantity' => 1, 'line_total' => 100.0]),
    ]);

    $item = $order->items()->first();
    expect((float) $item->quantity)->toBe(1.0);
    expect((float) $item->total)->toBe(100.0);
    expect($order->items()->count())->toBe(1);
});

// --- Отмена позиции ---

test('syncItems исключает отменённые позиции (cancelled)', function () {
    $order = makeOrder(['shop_order_id' => 502]);
    $service = app(OrderImportService::class);

    $service->syncItems($order, [
        makeItem(['id' => '1', 'product_id' => 'guid-1', 'product_name' => 'Товар A', 'item_status' => 'confirmed', 'line_total' => 200.0]),
        makeItem(['id' => '2', 'product_id' => 'guid-2', 'product_name' => 'Товар B', 'item_status' => 'cancelled', 'line_total' => 50.0]),
    ]);

    expect($order->items()->pluck('name')->all())->toBe(['Товар A']);
    expect((float) $order->fresh()->total)->toBe(200.0);
});

// --- Фактический вес весового товара ---

test('syncItems пишет фактический вес в кг и сумму из line_total', function () {
    $order = makeOrder(['shop_order_id' => 503]);
    $service = app(OrderImportService::class);

    // 350 г при 250 ₽/кг → 0.350 кг, сумма 87.50
    $service->syncItems($order, [
        makeItem(['product_name' => 'Яблоки', 'actual_weight' => 350, 'unit_price_effective' => 250.0, 'line_total' => 87.50, 'quantity' => 1]),
    ]);

    $item = $order->items()->first();
    expect((float) $item->quantity)->toBe(0.350);
    expect((float) $item->total)->toBe(87.50);
});

// --- Повторная выгрузка: exported_at сбрасывается ---

test('syncItems сбрасывает exported_at для повторной выгрузки в 1С', function () {
    $order = makeOrder(['shop_order_id' => 504, 'exported_at' => now()]);
    $service = app(OrderImportService::class);

    $service->syncItems($order, [makeItem()]);

    expect($order->fresh()->exported_at)->toBeNull();
});

// --- total = сумма позиций + доставка ---

test('syncItems учитывает delivery_cost в сумме заказа', function () {
    $order = makeOrder(['shop_order_id' => 505, 'delivery_cost' => 99.0]);
    $service = app(OrderImportService::class);

    $service->syncItems($order, [makeItem(['line_total' => 200.0])]);

    expect((float) $order->fresh()->total)->toBe(299.0);
});

// --- Защита: пустой/полностью отменённый payload не затирает состав ---

test('syncItems не затирает состав если все позиции отфильтрованы', function () {
    $order = makeOrder(['shop_order_id' => 506]);
    $order->items()->create(['external_id' => 'guid-1', 'name' => 'Существующий', 'quantity' => 1, 'price' => 100, 'total' => 100]);
    $service = app(OrderImportService::class);

    $service->syncItems($order, [
        makeItem(['item_status' => 'cancelled']),
        makeItem(['id' => '2_origin', 'item_status' => 'replaced']),
    ]);

    expect($order->items()->count())->toBe(1);
    expect($order->items()->first()->name)->toBe('Существующий');
});

// --- Интеграция: order.updated с items через OrderConsumer пересобирает состав ---

test('OrderConsumer: order.updated с items обновляет состав существующего заказа', function () {
    $order = makeOrder(['shop_order_id' => 600, 'status' => Order::STATUS_ASSEMBLED, 'exported_at' => now()]);
    $order->items()->create(['external_id' => 'guid-old', 'name' => 'Старый', 'quantity' => 1, 'price' => 100, 'total' => 100]);
    $consumer = app(OrderConsumer::class);

    $body = json_encode([
        'event' => 'order.updated',
        'payload' => [
            'id' => 600,
            'status' => 'in_delivery',
            'items' => [
                makeItem(['id' => '1', 'product_id' => 'guid-new', 'product_name' => 'Новый собранный', 'line_total' => 250.0, 'quantity' => 1]),
            ],
        ],
    ]);

    $message = $this->createMock(AMQPMessage::class);
    $message->method('getBody')->willReturn($body);
    $message->method('getDeliveryTag')->willReturn(1);
    $message->method('getRoutingKey')->willReturn('order.updated');

    expect($consumer->handle($message))->toBeTrue();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_IN_DELIVERY);
    expect($fresh->exported_at)->toBeNull();
    expect($order->items()->pluck('name')->all())->toBe(['Новый собранный']);
});

// --- order.updated только со статусом (без items) состав не трогает ---

test('OrderConsumer: order.updated без items сохраняет прежний состав', function () {
    $order = makeOrder(['shop_order_id' => 601, 'status' => Order::STATUS_ASSEMBLED]);
    $order->items()->create(['external_id' => 'guid-1', 'name' => 'Прежний', 'quantity' => 1, 'price' => 100, 'total' => 100]);
    $consumer = app(OrderConsumer::class);

    $body = json_encode([
        'event' => 'order.updated',
        'payload' => ['id' => 601, 'status' => 'delivered'],
    ]);

    $message = $this->createMock(AMQPMessage::class);
    $message->method('getBody')->willReturn($body);
    $message->method('getDeliveryTag')->willReturn(1);
    $message->method('getRoutingKey')->willReturn('order.updated');

    expect($consumer->handle($message))->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_DELIVERED);
    expect($order->items()->pluck('name')->all())->toBe(['Прежний']);
});
