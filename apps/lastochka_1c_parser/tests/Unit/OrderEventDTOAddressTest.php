<?php

declare(strict_types=1);

use App\RabbitMQ\DTO\OrderEventDTO;
use Tests\TestCase;

uses(TestCase::class);

test('buildDeliveryAddress composes structured address object', function () {
    $message = [
        'address' => [
            'address' => 'ул. Ленина, д. 10',
            'entrance' => '2',
            'intercom' => '15К',
            'floor' => '3',
            'flat' => '47',
            'latitude' => 55.7,
            'longitude' => 37.6,
        ],
    ];

    expect(OrderEventDTO::buildDeliveryAddress($message))
        ->toBe('ул. Ленина, д. 10, подъезд 2, домофон 15К, этаж 3, кв. 47');
});

test('buildDeliveryAddress skips empty parts', function () {
    $message = ['address' => ['address' => 'пр. Мира, 5', 'flat' => '12']];

    expect(OrderEventDTO::buildDeliveryAddress($message))->toBe('пр. Мира, 5, кв. 12');
});

test('buildDeliveryAddress prefers flat delivery_address string when present', function () {
    $message = [
        'delivery_address' => 'Готовая строка адреса',
        'address' => ['address' => 'игнор'],
    ];

    expect(OrderEventDTO::buildDeliveryAddress($message))->toBe('Готовая строка адреса');
});

test('buildDeliveryAddress returns null when no usable address', function () {
    expect(OrderEventDTO::buildDeliveryAddress([]))->toBeNull()
        ->and(OrderEventDTO::buildDeliveryAddress(['address' => []]))->toBeNull()
        ->and(OrderEventDTO::buildDeliveryAddress(['address' => 'строка вместо объекта']))->toBeNull();
});

test('fromMessage maps shipping_method, payment_type, address and client_comment', function () {
    $message = [
        'id' => 100,
        'status' => 'assembled',
        'shop_code' => 'aladdin',
        'client_info' => ['name' => 'Иван', 'phone' => '+79990001122'],
        'items' => [['product_id' => 5, 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]],
        'address' => ['address' => 'ул. Садовая, 1', 'flat' => '9'],
        'shipping_method' => 'pickup',
        'payment_type' => 'card',
        'client_comment' => 'позвонить заранее',
    ];

    $dto = OrderEventDTO::fromMessage($message);

    expect($dto->shippingMethod)->toBe('pickup')
        ->and($dto->paymentType)->toBe('card')
        ->and($dto->deliveryAddress)->toBe('ул. Садовая, 1, кв. 9')
        ->and($dto->comment)->toBe('позвонить заранее');
});

test('fromMessage shipping/payment/address are null when absent', function () {
    $message = [
        'id' => 101,
        'status' => 'assembled',
        'shop_code' => 'aladdin',
        'client_info' => ['phone' => '+79990001122'],
        'items' => [['product_id' => 5]],
    ];

    $dto = OrderEventDTO::fromMessage($message);

    expect($dto->shippingMethod)->toBeNull()
        ->and($dto->paymentType)->toBeNull()
        ->and($dto->deliveryAddress)->toBeNull();
});
