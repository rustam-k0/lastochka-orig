<?php

declare(strict_types=1);

use App\Models\Product;
use App\RabbitMQ\DTO\OrderEventDTO;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Количество позиции заказа для 1С — в единице товара 1С (кг/г/л/мл/шт).
 *
 * Весовой определялся по наличию actual_weight: невзвешенная весовая позиция
 * уходила порциями как штуки (3 порции по 300 г → «3», 1С читала 3 кг; прод:
 * 2 строки в июле), а штучный товар с залипшим весом — килограммами (прод:
 * 1 строка 12.09). Теперь весовость — по единице товара 1С, без взвешивания
 * количество = порции × portion_size из события курьерки.
 */

uses(RefreshDatabase::class);

function unitProduct(string $unit, string $externalId): Product
{
    return Product::factory()->create([
        'external_id' => $externalId,
        'attributes' => ['unit' => $unit],
    ]);
}

test('весовой без взвешивания: порции × portion_size в кг', function () {
    $product = unitProduct('кг', 'fillet-guid');

    $row = (new OrderEventTransformer())->buildOrderItemRow([
        'quantity' => 3,
        'portion_size' => 300,
        'unit_price' => 178.2,
        'line_total' => 534.6,
    ], $product);

    expect($row['quantity'])->toBe(0.9)
        ->and($row['total'])->toBe(534.6)
        ->and($row['base_unit_code'])->toBe(OrderEventTransformer::UNIT_KG)
        ->and($row['actual_weight_grams'])->toBeNull();
});

test('весовой взвешенный: фактический вес в кг', function () {
    $product = unitProduct('166', 'fillet-guid');

    $row = (new OrderEventTransformer())->buildOrderItemRow([
        'quantity' => 2,
        'portion_size' => 300,
        'actual_weight' => 528,
        'unit_price' => 178.2,
        'line_total' => 313.63,
    ], $product);

    expect($row['quantity'])->toBe(0.528)
        ->and($row['actual_weight_grams'])->toBe(528)
        ->and($row['base_unit_code'])->toBe(OrderEventTransformer::UNIT_KG);
});

test('штучный с залипшим весом уходит штуками', function () {
    $product = unitProduct('шт', 'coffee-guid');

    $row = (new OrderEventTransformer())->buildOrderItemRow([
        'quantity' => 1,
        'actual_weight' => 200,
        'unit_price' => 1900,
        'line_total' => 1900,
    ], $product);

    expect($row['quantity'])->toBe(1.0)
        ->and($row['base_unit_code'])->toBe(OrderEventTransformer::UNIT_PIECE)
        ->and($row['actual_weight_grams'])->toBeNull();
});

test('товар с базой «граммы» уходит в граммах', function () {
    $product = unitProduct('г', 'spice-guid');

    $row = (new OrderEventTransformer())->buildOrderItemRow([
        'quantity' => 2,
        'portion_size' => 50,
        'unit_price' => 30,
        'line_total' => 60,
    ], $product);

    expect($row['quantity'])->toBe(100.0)
        ->and($row['base_unit_code'])->toBe(OrderEventTransformer::UNIT_GRAM);
});

test('импорт заказа OrderImportService считает весовую позицию тем же правилом', function () {
    makeIntegrationConfig();
    unitProduct('кг', 'fillet-guid');

    $order = (new OrderImportService())->import(OrderEventDTO::fromMessage([
        'event' => 'order.created',
        'event_id' => 'evt-units',
        'source' => 'aladdin',
        'shop_code' => 'aladdin',
        'timestamp' => '2026-10-14T12:00:00+03:00',
        'id' => 777001,
        'status' => 'assembled',
        'client_info' => ['name' => 'Иван', 'phone' => '+79001234567'],
        'grand_total' => 534.6,
        'items' => [[
            'id' => '1',
            'source' => 'aladdin',
            'product_id' => 'fillet-guid',
            'product_name' => 'Филе грудки',
            'quantity' => 3,
            'portion_size' => 300,
            'unit_price' => 178.2,
            'unit_price_effective' => 178.2,
            'line_total' => 534.6,
            'item_status' => 'confirmed',
        ]],
    ]));

    $item = $order->items()->firstOrFail();
    expect((float) $item->quantity)->toBe(0.9)
        ->and($item->base_unit_code)->toBe(OrderEventTransformer::UNIT_KG);
});
