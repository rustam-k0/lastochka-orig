<?php

use App\Models\Order;
use App\RabbitMQ\Consumers\LoyaltyCardConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // orders.shop_id NOT NULL — резолвится через IntegrationConfig::resolveShopIdByCode.
    makeIntegrationConfig();
});

it('creates loyalty order from event payload', function () {
    $order = LoyaltyCardConsumer::createLoyaltyOrder([
        'loyalty_id' => 'LTY-01ABC',
        'card_number' => '07123456',
        'name' => 'Иванов Иван',
        'phone' => '+79990001122',
        'shop_code' => 'djami_store',
    ]);

    expect($order)->not->toBeNull()
        ->and($order->source)->toBe(Order::SOURCE_LOYALTY_CARD)
        ->and($order->status)->toBe(Order::STATUS_ASSEMBLED)
        ->and((float) $order->total)->toBe(0.0)
        // Заказ создания карты: карты в 1С ещё нет → «Признак наличия карты» = Нет
        // (has_card=false); номер уходит отдельным реквизитом «Номер карты».
        ->and($order->has_card)->toBeFalse()
        ->and($order->card_number)->toBe('07123456')
        ->and($order->loyalty_id)->toBe('LTY-01ABC');

    $item = $order->items()->first();
    expect($item->name)->toBe('Лояльность')
        ->and((float) $item->price)->toBe(0.0)
        ->and((int) $item->quantity)->toBe(1)
        ->and($item->external_id)->toBe(config('exchange1c.loyalty_nomenclature_id'));
});

it('is idempotent by card_number', function () {
    $payload = [
        'loyalty_id' => 'LTY-01ABC',
        'card_number' => '07123456',
        'name' => 'Иванов Иван',
        'phone' => '+79990001122',
    ];

    LoyaltyCardConsumer::createLoyaltyOrder($payload);
    $second = LoyaltyCardConsumer::createLoyaltyOrder($payload);

    expect($second)->toBeNull()
        ->and(Order::where('source', Order::SOURCE_LOYALTY_CARD)->count())->toBe(1);
});

it('skips payload without card_number or loyalty_id', function () {
    expect(LoyaltyCardConsumer::createLoyaltyOrder(['loyalty_id' => 'x']))->toBeNull()
        ->and(LoyaltyCardConsumer::createLoyaltyOrder(['card_number' => '07000000']))->toBeNull()
        ->and(Order::count())->toBe(0);
});

it('loyalty order appears in forExport scope', function () {
    LoyaltyCardConsumer::createLoyaltyOrder([
        'loyalty_id' => 'LTY-01ABC',
        'card_number' => '07123456',
        'phone' => '+79990001122',
    ]);

    expect(Order::forExport()->count())->toBe(1);
});

it('creates loyalty order with empty phone when payload omits it', function () {
    // orders.customer_phone NOT NULL: без фолбэка событие без телефона падало бы
    // в SQL error → 3 ретрая → DLQ (заказ «Лояльность» терялся бы навсегда).
    $order = LoyaltyCardConsumer::createLoyaltyOrder([
        'loyalty_id' => 'LTY-NOPHONE',
        'card_number' => '07777777',
        'name' => 'Без Телефона',
    ]);

    expect($order)->not->toBeNull()
        ->and($order->customer_phone)->toBe('')
        ->and(Order::where('source', Order::SOURCE_LOYALTY_CARD)->count())->toBe(1);
});

it('handles flat AMQP message body (real storefront outbox contract)', function () {
    // storefront публикует кастомные события ФЛАТ: поля на верхнем уровне тела,
    // БЕЗ вложенного payload — пиннит контракт `$data['payload'] ?? $data`.
    $message = new \PhpAmqpLib\Message\AMQPMessage(json_encode([
        'event_id' => 'evt-lty-1',
        'event_type' => 'loyalty_card.created',
        'event_source' => 'storefront',
        'shop_code' => 'djami_store',
        'user_id' => 42,
        'loyalty_id' => 'LTY-FLAT',
        'card_number' => '07654321',
        'name' => 'Флат Контракт',
        'phone' => '+79990002233',
    ]));

    $ack = (new LoyaltyCardConsumer())->handle($message);

    expect($ack)->toBeTrue();

    $order = Order::where('source', Order::SOURCE_LOYALTY_CARD)->first();
    expect($order)->not->toBeNull()
        ->and($order->card_number)->toBe('07654321')
        ->and($order->loyalty_id)->toBe('LTY-FLAT')
        ->and($order->status)->toBe(Order::STATUS_ASSEMBLED);
});
