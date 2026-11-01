<?php

use App\Models\BonusOperation;
use App\Services\Exchange1C\BonusOperationExportHandler;

it('builds bonus operation xml document', function () {
    $op = new BonusOperation();
    $op->forceFill([
        'operation_id' => 'OP-EXP-1', 'loyalty_id' => 'LTY-OUT', 'type' => 'spend',
        'amount' => 75, 'source' => 'online', 'order_external_id' => 'EXT-3',
        'direction' => BonusOperation::DIRECTION_OUTBOUND, 'status' => 'pending',
    ]);

    $xml = (new BonusOperationExportHandler())->buildXml([$op]);

    expect($xml)->toContain('Бонусная операция')
        ->and($xml)->toContain('OP-EXP-1')
        ->and($xml)->toContain('LTY-OUT')
        ->and($xml)->toContain('75')
        ->and($xml)->toContain('EXT-3');
});
