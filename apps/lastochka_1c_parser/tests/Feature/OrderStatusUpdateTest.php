<?php

use App\Models\Order;
use App\RabbitMQ\Consumers\OrderConsumer;
use App\Services\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpAmqpLib\Message\AMQPMessage;

uses(RefreshDatabase::class);

// --- OrderImportService::updateStatus() ---

test('updateStatus: in_delivery транслируется в ВДоставке', function () {
    $order = Order::factory()->create(['shop_order_id' => 100, 'status' => Order::STATUS_ASSEMBLED]);
    $service = new OrderImportService();

    $result = $service->updateStatus(100, 'in_delivery');

    expect($result)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_IN_DELIVERY);
});

test('updateStatus: delivered транслируется в Доставлен', function () {
    $order = Order::factory()->create(['shop_order_id' => 101, 'status' => Order::STATUS_IN_DELIVERY]);
    $service = new OrderImportService();

    $result = $service->updateStatus(101, 'delivered');

    expect($result)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_DELIVERED);
});

test('updateStatus: cancelled транслируется в Отменен', function () {
    $order = Order::factory()->create(['shop_order_id' => 102, 'status' => Order::STATUS_NEW]);
    $service = new OrderImportService();

    $result = $service->updateStatus(102, 'cancelled');

    expect($result)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
});

test('updateStatus: неизвестный статус возвращает false', function () {
    Order::factory()->create(['shop_order_id' => 103]);
    $service = new OrderImportService();

    expect($service->updateStatus(103, 'assembling'))->toBeFalse();
    expect($service->updateStatus(103, 'created'))->toBeFalse();
});

test('updateStatus: заказ не найден возвращает false', function () {
    $service = new OrderImportService();

    expect($service->updateStatus(99999, 'in_delivery'))->toBeFalse();
});

test('updateStatus: одинаковый статус не обновляет и возвращает false', function () {
    Order::factory()->create(['shop_order_id' => 104, 'status' => Order::STATUS_IN_DELIVERY]);
    $service = new OrderImportService();

    expect($service->updateStatus(104, 'in_delivery'))->toBeFalse();
});

// --- Order::scopeForExport() ---

test('scopeForExport фильтрует по статусам Собран/ВДоставке/Доставлен/Отменен и exported_at=null', function () {
    Order::factory()->create(['status' => Order::STATUS_ASSEMBLED, 'exported_at' => null]);
    Order::factory()->create(['status' => Order::STATUS_IN_DELIVERY, 'exported_at' => null]);
    Order::factory()->create(['status' => Order::STATUS_DELIVERED, 'exported_at' => null]);
    Order::factory()->create(['status' => Order::STATUS_CANCELLED, 'exported_at' => null]);
    Order::factory()->create(['status' => Order::STATUS_IN_DELIVERY, 'exported_at' => now()]);
    Order::factory()->create(['status' => Order::STATUS_NEW, 'exported_at' => null]);
    Order::factory()->create(['status' => Order::STATUS_ASSEMBLING, 'exported_at' => null]);

    $forExport = Order::forExport()->get();

    expect($forExport)->toHaveCount(4);
});

// --- OrderConsumer с order.updated ---

test('OrderConsumer: order.updated с in_delivery обновляет статус', function () {
    $order = Order::factory()->create(['shop_order_id' => 200, 'status' => Order::STATUS_ASSEMBLED]);
    $consumer = app(OrderConsumer::class);

    $messageBody = json_encode([
        'event' => 'order.updated',
        'source' => 'aladdin_shop',
        'timestamp' => now()->toIso8601String(),
        'payload' => [
            'id' => 200,
            'status' => 'in_delivery',
        ],
    ]);

    $message = $this->createMock(AMQPMessage::class);
    $message->method('getBody')->willReturn($messageBody);
    $message->method('getDeliveryTag')->willReturn(1);
    $message->method('getRoutingKey')->willReturn('order.updated');

    $result = $consumer->handle($message);

    expect($result)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_IN_DELIVERY);
});

test('OrderConsumer: order.updated с assembling игнорируется (ACK)', function () {
    $order = Order::factory()->create(['shop_order_id' => 201, 'status' => Order::STATUS_ASSEMBLING]);
    $consumer = app(OrderConsumer::class);

    $messageBody = json_encode([
        'event' => 'order.updated',
        'source' => 'aladdin_shop',
        'timestamp' => now()->toIso8601String(),
        'payload' => [
            'id' => 201,
            'status' => 'assembling',
        ],
    ]);

    $message = $this->createMock(AMQPMessage::class);
    $message->method('getBody')->willReturn($messageBody);
    $message->method('getDeliveryTag')->willReturn(1);
    $message->method('getRoutingKey')->willReturn('order.updated');

    $result = $consumer->handle($message);

    expect($result)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_ASSEMBLING);
});

test('OrderConsumer: order.updated с cancelled отменяет заказ', function () {
    $order = Order::factory()->create(['shop_order_id' => 202, 'status' => Order::STATUS_NEW]);
    $consumer = app(OrderConsumer::class);

    $messageBody = json_encode([
        'event' => 'order.updated',
        'source' => 'aladdin_shop',
        'timestamp' => now()->toIso8601String(),
        'payload' => [
            'id' => 202,
            'status' => 'cancelled',
        ],
    ]);

    $message = $this->createMock(AMQPMessage::class);
    $message->method('getBody')->willReturn($messageBody);
    $message->method('getDeliveryTag')->willReturn(1);
    $message->method('getRoutingKey')->willReturn('order.updated');

    $result = $consumer->handle($message);

    expect($result)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
});

// --- OrderExportHandler::buildRequisites() ---

test('buildRequisites передаёт русскоязычный статус в XML', function () {
    $order = Order::factory()->create(['status' => Order::STATUS_NEW]);

    $handler = new \App\Services\Exchange1C\OrderExportHandler();
    $buildRequisites = new ReflectionMethod($handler, 'buildRequisites');

    $dom = new DOMDocument('1.0', 'UTF-8');
    $element = $buildRequisites->invoke($handler, $dom, $order);

    $xml = $dom->saveXML($element);

    // Реквизит переименован в «Статуса заказа ИД» (коммит 09583f4), «Статус заказа»
    // OrderStatusSyncService принимает только как устаревший fallback
    expect($xml)->toContain('<Наименование>Статуса заказа ИД</Наименование><Значение>Новый</Значение>');
});

test('buildRequisites передаёт статус НаСборке в XML', function () {
    $order = Order::factory()->create(['status' => Order::STATUS_ASSEMBLING]);

    $handler = new \App\Services\Exchange1C\OrderExportHandler();
    $buildRequisites = new ReflectionMethod($handler, 'buildRequisites');

    $dom = new DOMDocument('1.0', 'UTF-8');
    $element = $buildRequisites->invoke($handler, $dom, $order);

    $xml = $dom->saveXML($element);

    expect($xml)->toContain('<Значение>НаСборке</Значение>');
});
