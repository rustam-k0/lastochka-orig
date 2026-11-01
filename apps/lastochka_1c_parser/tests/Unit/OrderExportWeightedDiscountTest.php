<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Exchange1C\OrderExportHandler;
use Tests\TestCase;

uses(TestCase::class)->group('order-weighted');

function buildItemsXml(Order $order): string
{
    $handler = new OrderExportHandler();
    $m = new ReflectionMethod(OrderExportHandler::class, 'buildOrderItems');
    $m->setAccessible(true);

    $dom = new DOMDocument('1.0', 'UTF-8');
    $el = $m->invoke($handler, $dom, $order);
    $dom->appendChild($el);

    return $dom->saveXML();
}

function allocateLineTotals(Order $order): array
{
    $handler = new OrderExportHandler();
    $m = new ReflectionMethod(OrderExportHandler::class, 'allocateDiscountedLineTotals');
    $m->setAccessible(true);

    return $m->invoke($handler, $order);
}

function makeOrderWithItems(array $attributes, array $items): Order
{
    $order = new Order($attributes);
    $order->setRelation('items', collect($items));

    return $order;
}

function buildDocumentXml(Order $order): string
{
    $handler = new OrderExportHandler();
    $m = new ReflectionMethod(OrderExportHandler::class, 'buildOrderDocument');
    $m->setAccessible(true);

    $dom = new DOMDocument('1.0', 'UTF-8');
    $el = $m->invoke($handler, $dom, $order);
    $dom->appendChild($el);

    return $dom->saveXML();
}

test('все позиции выгружаются в «шт» (796) — 1С клиента ведёт номенклатуру в штуках', function () {
    // Откат: даже весовой товар (base_unit_code=166) уходит в «шт», т.к. выгрузка
    // в «кг» (166) ломала приём заказов на стороне 1С.
    $order = makeOrderWithItems(
        ['discount' => 0, 'delivery_cost' => 0],
        [
            new OrderItem(['external_id' => 'g1', 'name' => 'Бананы', 'price' => 113, 'quantity' => 0.316, 'total' => 35.71, 'base_unit_code' => '166']),
            new OrderItem(['external_id' => 'g2', 'name' => 'Чипсы', 'price' => 50, 'quantity' => 2, 'total' => 100, 'base_unit_code' => '796']),
        ]
    );

    $xml = buildItemsXml($order);

    expect($xml)->toContain('Код="796"')->toContain('шт')
        ->not->toContain('Код="166"');
});

test('при discount = 0 цены/суммы позиций остаются полными (gross)', function () {
    $order = makeOrderWithItems(
        ['discount' => 0, 'delivery_cost' => 0],
        [
            new OrderItem(['external_id' => 'g1', 'name' => 'A', 'price' => 80, 'quantity' => 1, 'total' => 80]),
            new OrderItem(['external_id' => 'g2', 'name' => 'B', 'price' => 130, 'quantity' => 2, 'total' => 260]),
        ]
    );

    expect(allocateLineTotals($order))->toBe([80.0, 260.0]);

    $xml = buildItemsXml($order);
    expect($xml)->toContain('<ЦенаЗаЕдиницу>80.00</ЦенаЗаЕдиницу>')
        ->toContain('<ЦенаЗаЕдиницу>130.00</ЦенаЗаЕдиницу>');
});

test('скидка раскидывается по позициям — цены/суммы приходят со скидкой (net), заказ №99', function () {
    // Товары: Паста 80 (×1), Булочки 164.60 (×1), Хычин 130 (×2 = 260). Σ = 504.60.
    // Скидка 50.46 = ровно 10% → каждая позиция −10%: 72.00 / 148.14 / 234.00.
    $order = makeOrderWithItems(
        ['discount' => 50.46, 'delivery_cost' => 0],
        [
            new OrderItem(['external_id' => 'g1', 'name' => 'Паста', 'price' => 80, 'quantity' => 1, 'total' => 80]),
            new OrderItem(['external_id' => 'g2', 'name' => 'Булочки', 'price' => 164.60, 'quantity' => 1, 'total' => 164.60]),
            new OrderItem(['external_id' => 'g3', 'name' => 'Хычин', 'price' => 130, 'quantity' => 2, 'total' => 260]),
        ]
    );

    expect(allocateLineTotals($order))->toBe([72.0, 148.14, 234.0]);

    $xml = buildItemsXml($order);
    // Сумма строки — net; цена за единицу пересчитана из net (Хычин 234/2 = 117.00).
    expect($xml)->toContain('<Сумма>72.00</Сумма>')
        ->toContain('<Сумма>148.14</Сумма>')
        ->toContain('<Сумма>234.00</Сумма>')
        ->toContain('<ЦенаЗаЕдиницу>117.00</ЦенаЗаЕдиницу>');
});

test('Σ net позиций = Σ gross − discount, остаток округления на последней позиции', function () {
    // Неровная скидка: 100 + 50 = 150, discount 10 (не делится ровно).
    // idx0: round(10*100/150)=6.67; net 93.33. idx1 (last): 50 − (10 − 6.67) = 46.67.
    $order = makeOrderWithItems(
        ['discount' => 10, 'delivery_cost' => 0],
        [
            new OrderItem(['external_id' => 'g1', 'name' => 'A', 'price' => 100, 'quantity' => 1, 'total' => 100]),
            new OrderItem(['external_id' => 'g2', 'name' => 'B', 'price' => 50, 'quantity' => 1, 'total' => 50]),
        ]
    );

    $net = allocateLineTotals($order);

    expect($net)->toBe([93.33, 46.67])
        ->and(round(array_sum($net), 2))->toBe(140.0); // 150 − 10
});

test('доставка НЕ дисконтируется — отдельная позиция остаётся по полной стоимости', function () {
    $order = makeOrderWithItems(
        ['discount' => 10, 'delivery_cost' => 200],
        [new OrderItem(['external_id' => 'g1', 'name' => 'A', 'price' => 100, 'quantity' => 1, 'total' => 100])]
    );

    $xml = buildItemsXml($order);

    // Товар со скидкой 90.00, доставка по полной 200.00.
    expect($xml)->toContain('<Наименование>A</Наименование>')
        ->toContain('<Сумма>90.00</Сумма>')
        ->toContain('<Наименование>Доставка</Наименование>')
        ->toContain('<Сумма>200.00</Сумма>');
});

test('документ НЕ содержит узел <Скидки> — скидка зашита в цены позиций', function () {
    // Σ позиций(net) 454.14 + доставка 200 = 654.14 = order.total.
    $order = (new Order())->forceFill([
        'number' => '99',
        'status' => 'Собран',
        'customer_name' => 'Тест Клиент',
        'total' => 654.14,
        'discount' => 50.46,
        'delivery_cost' => 200,
        'created_at' => \Illuminate\Support\Carbon::parse('2026-06-26 20:30:53'),
    ]);
    $order->setRelation('items', collect([
        new OrderItem(['external_id' => 'g1', 'name' => 'Паста', 'price' => 80, 'quantity' => 1, 'total' => 80]),
        new OrderItem(['external_id' => 'g2', 'name' => 'Булочки', 'price' => 164.60, 'quantity' => 1, 'total' => 164.60]),
        new OrderItem(['external_id' => 'g3', 'name' => 'Хычин', 'price' => 130, 'quantity' => 2, 'total' => 260]),
    ]));

    $xml = buildDocumentXml($order);

    expect($xml)->not->toContain('<Скидки>')
        ->and($xml)->toContain('<Сумма>654.14</Сумма>'); // итог документа = net + доставка
});
