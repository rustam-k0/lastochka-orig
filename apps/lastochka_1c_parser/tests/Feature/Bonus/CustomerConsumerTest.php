<?php

use App\Models\Contragent;
use App\RabbitMQ\Consumers\CustomerConsumer;
use App\RabbitMQ\DTO\CustomerSyncDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpAmqpLib\Message\AMQPMessage;

uses(RefreshDatabase::class);

it('maps customer payload to dto', function () {
    $dto = CustomerSyncDTO::fromMessage([
        'loyalty_id' => 'LTY-EE', 'name' => 'Пётр', 'phone' => '+79993334455',
        'bonus_balance' => 250, 'id' => 'GUID-9', 'shop_code' => 'djami_store',
    ]);
    expect($dto->loyaltyId)->toBe('LTY-EE')
        ->and($dto->bonusBalance)->toBe(250)
        ->and($dto->phone)->toBe('+79993334455')
        ->and($dto->externalId)->toBe('GUID-9');
});

it('leaves bonus balance null when payload omits it (partial update guard)', function () {
    $dto = CustomerSyncDTO::fromMessage([
        'loyalty_id' => 'LTY-EE', 'name' => 'Пётр', 'phone' => '+79993334455',
        'id' => 'GUID-9', 'shop_code' => 'djami_store',
    ]);
    expect($dto->bonusBalance)->toBeNull()
        ->and($dto->loyaltyId)->toBe('LTY-EE');
});

it('maps card_number from customer payload to dto', function () {
    $dto = CustomerSyncDTO::fromMessage([
        'loyalty_id' => 'LTY-EE', 'name' => 'Пётр', 'phone' => '+79993334455',
        'card_number' => '07123456', 'id' => 'GUID-9', 'shop_code' => 'djami_store',
    ]);
    expect($dto->cardNumber)->toBe('07123456');
});

it('saves card_number from customer event into contragents', function () {
    $message = new AMQPMessage(json_encode([
        'loyalty_id' => 'LTY-CARD1',
        'name' => 'Иван Карточкин',
        'phone' => '+79990001111',
        'card_number' => '07123456',
    ]));

    $ack = (new CustomerConsumer())->handle($message);

    expect($ack)->toBeTrue();

    $contragent = Contragent::where('loyalty_id', 'LTY-CARD1')->first();
    expect($contragent)->not->toBeNull()
        ->and($contragent->card_number)->toBe('07123456');
});

it('does not overwrite existing card_number when event omits it (partial update guard)', function () {
    $existing = Contragent::create([
        'loyalty_id' => 'LTY-CARD2',
        'name' => 'Пётр Карточкин',
        'phone' => '+79990002222',
        'card_number' => '07999999',
    ]);

    $message = new AMQPMessage(json_encode([
        'loyalty_id' => 'LTY-CARD2',
        'name' => 'Пётр Карточкин',
        'phone' => '+79990002222',
        'bonus_balance' => 150,
    ]));

    $ack = (new CustomerConsumer())->handle($message);

    expect($ack)->toBeTrue();

    $existing->refresh();
    expect($existing->card_number)->toBe('07999999')
        ->and((int) $existing->bonus_balance)->toBe(150);
});
