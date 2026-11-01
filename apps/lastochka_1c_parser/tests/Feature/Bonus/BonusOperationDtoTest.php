<?php

use App\RabbitMQ\DTO\BonusOperationDTO;

it('maps incoming bonus operation payload', function () {
    $dto = BonusOperationDTO::fromMessage([
        'operation_id' => 'OP-55', 'loyalty_id' => 'LTY-GG', 'type' => 'spend',
        'amount' => 90, 'operation_source' => 'online', 'order_external_id' => 'EXT-1',
    ]);
    expect($dto->operationId)->toBe('OP-55')
        ->and($dto->amount)->toBe(90)
        ->and($dto->type)->toBe('spend')
        ->and($dto->source)->toBe('online')
        ->and($dto->orderExternalId)->toBe('EXT-1');
});
