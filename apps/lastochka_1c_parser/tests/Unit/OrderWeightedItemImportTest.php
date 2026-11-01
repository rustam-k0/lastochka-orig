<?php

declare(strict_types=1);

use App\RabbitMQ\Transformers\OrderEventTransformer;
use Tests\TestCase;

uses(TestCase::class)->group('order-weighted');

test('buildOrderItemRow: весовой товар → количество в кг, единица 166, сумма line_total', function () {
    $row = (new OrderEventTransformer())->buildOrderItemRow([
        'actual_weight' => 316,
        'unit_price_effective' => 113,
        'unit_price' => 113,
        'line_total' => 35.71,
        'quantity' => 1,
    ]);

    expect($row['quantity'])->toBe(0.316)
        ->and($row['price'])->toBe(113.0)
        ->and($row['total'])->toBe(35.71)
        ->and($row['actual_weight_grams'])->toBe(316)
        ->and($row['base_unit_code'])->toBe(OrderEventTransformer::UNIT_KG);
});

test('buildOrderItemRow: штучный товар (без веса) → количество как есть, единица 796', function () {
    $row = (new OrderEventTransformer())->buildOrderItemRow([
        'unit_price_effective' => 50,
        'line_total' => 100,
        'quantity' => 2,
    ]);

    expect($row['quantity'])->toBe(2.0)
        ->and($row['price'])->toBe(50.0)
        ->and($row['total'])->toBe(100.0)
        ->and($row['actual_weight_grams'])->toBeNull()
        ->and($row['base_unit_code'])->toBe(OrderEventTransformer::UNIT_PIECE);
});

test('buildOrderItemRow: цена берётся из unit_price если нет effective; вес 0 → штучный', function () {
    $row = (new OrderEventTransformer())->buildOrderItemRow([
        'actual_weight' => 0,
        'unit_price' => 80,
        'line_total' => 80,
        'quantity' => 1,
    ]);

    expect($row['price'])->toBe(80.0)
        ->and($row['base_unit_code'])->toBe(OrderEventTransformer::UNIT_PIECE)
        ->and($row['actual_weight_grams'])->toBeNull();
});

// Маппинг discount_total → discount в fromEvent — тривиальная строка, зависит
// от IntegrationConfig::resolveShopIdByCode (БД). Проверяется E2E на dev при
// переимпорте заказа (orders.discount должен заполниться из payload.discount_total).
