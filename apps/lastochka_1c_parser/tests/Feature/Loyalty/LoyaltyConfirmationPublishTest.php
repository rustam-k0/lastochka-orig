<?php

use App\Models\Order;
use App\Services\Exchange1C\OrderExportHandler;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeLoyaltyOrder(array $attrs = []): Order
{
    return Order::create(array_merge([
        'number' => 'L-TEST-' . uniqid(),
        'status' => Order::STATUS_ASSEMBLED,
        'source' => Order::SOURCE_LOYALTY_CARD,
        'shop_id' => 1,
        'customer_name' => 'Клиент 07123456',
        'customer_phone' => '+79990000000',
        'total' => 0,
        'has_card' => true,
        'card_number' => '07123456',
        'loyalty_id' => 'LTY-01ABC',
        'received_at' => now(),
    ], $attrs));
}

it('publishes confirmed for exported loyalty orders', function () {
    $order = makeLoyaltyOrder();

    $handler = new OrderExportHandler();
    $ref = new ReflectionMethod($handler, 'publishLoyaltyConfirmations');
    $ref->setAccessible(true);
    $ref->invoke($handler, collect([$order]));

    $outbox = EventOutbox::where('routing_key', 'loyalty_card.confirmed')->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload['card_number'])->toBe('07123456')
        ->and($outbox->payload['loyalty_id'])->toBe('LTY-01ABC')
        ->and($order->refresh()->confirmed_published_at)->not->toBeNull();
});

it('stepSuccess on a fresh handler instance confirms exported loyalty orders', function () {
    // Регрессия реального протокола: mavsan создаёт НОВЫЙ инстанс хендлера на
    // каждый HTTP-запрос — mode=success приходит отдельным запросом, и in-memory
    // $exportedOrderIds там пуст. stepSuccess обязан подтверждать по БД.
    $exported = makeLoyaltyOrder(['exported_at' => now()->subSeconds(30), 'card_number' => '07000010']);
    $notExported = makeLoyaltyOrder(['card_number' => '07000011']); // ещё не выгружен

    $result = (new OrderExportHandler())->stepSuccess();

    expect($result)->toBe('success')
        ->and($exported->refresh()->confirmed_published_at)->not->toBeNull()
        ->and($notExported->refresh()->confirmed_published_at)->toBeNull();

    $published = EventOutbox::where('routing_key', 'loyalty_card.confirmed')->get();
    expect($published)->toHaveCount(1)
        ->and($published->first()->payload['card_number'])->toBe('07000010');
});

it('does not republish already published confirmation', function () {
    $order = makeLoyaltyOrder(['confirmed_published_at' => now()]);

    // Выборка в getXML/stepSuccess фильтрует по whereNull(confirmed_published_at)
    $stale = Order::where('source', Order::SOURCE_LOYALTY_CARD)
        ->whereNull('confirmed_published_at')
        ->get();

    expect($stale)->toHaveCount(0);
});

it('retry selects only stale exported loyalty orders', function () {
    makeLoyaltyOrder(['exported_at' => now()->subMinutes(30), 'card_number' => '07000001']);
    makeLoyaltyOrder(['exported_at' => now()->subMinutes(2), 'card_number' => '07000002']);
    makeLoyaltyOrder(['card_number' => '07000003']); // не выгружен

    $stale = Order::where('source', Order::SOURCE_LOYALTY_CARD)
        ->whereNotNull('exported_at')
        ->whereNull('confirmed_published_at')
        ->where('exported_at', '<', now()->subMinutes(10))
        ->get();

    expect($stale)->toHaveCount(1)
        ->and($stale->first()->card_number)->toBe('07000001');
});
