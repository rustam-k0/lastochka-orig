<?php

use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps has_card and card_number from order payload', function () {
    $attrs = (new OrderEventTransformer())->fromEvent([
        'payload' => [
            'id' => 101,
            'status' => 'assembled',
            'client_info' => ['name' => 'Иван', 'phone' => '+79990001122'],
            'grand_total' => 500,
            'has_card' => true,
            'card_number' => '07123456',
            'items' => [],
        ],
    ]);

    expect($attrs['has_card'])->toBeTrue()
        ->and($attrs['card_number'])->toBe('07123456');
});

it('defaults to no card when fields absent', function () {
    $attrs = (new OrderEventTransformer())->fromEvent([
        'payload' => [
            'id' => 102,
            'status' => 'assembled',
            'client_info' => ['name' => 'Иван', 'phone' => '+79990001122'],
            'grand_total' => 500,
            'items' => [],
        ],
    ]);

    expect($attrs['has_card'])->toBeFalse()
        ->and($attrs['card_number'])->toBeNull();
});

it('ignores card_number when has_card is false (unconfirmed card)', function () {
    $attrs = (new OrderEventTransformer())->fromEvent([
        'payload' => [
            'id' => 103,
            'status' => 'assembled',
            'client_info' => ['name' => 'Иван', 'phone' => '+79990001122'],
            'grand_total' => 500,
            'has_card' => false,
            'card_number' => '07123456',
            'items' => [],
        ],
    ]);

    expect($attrs['has_card'])->toBeFalse()
        ->and($attrs['card_number'])->toBeNull();
});
