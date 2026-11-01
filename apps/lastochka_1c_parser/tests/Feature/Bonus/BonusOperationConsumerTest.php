<?php

use App\RabbitMQ\Consumers\BonusOperationConsumer;
use App\RabbitMQ\DTO\BonusOperationDTO;

it('builds outbound operation row data from dto', function () {
    $dto = BonusOperationDTO::fromMessage([
        'operation_id' => 'OP-1', 'loyalty_id' => 'LTY-1', 'type' => 'earn',
        'amount' => 30, 'operation_source' => 'online',
    ]);
    $row = BonusOperationConsumer::toRow($dto);
    expect($row['direction'])->toBe('outbound_to_1c')
        ->and($row['operation_id'])->toBe('OP-1')
        ->and($row['status'])->toBe('pending')
        ->and($row['amount'])->toBe(30);
});
