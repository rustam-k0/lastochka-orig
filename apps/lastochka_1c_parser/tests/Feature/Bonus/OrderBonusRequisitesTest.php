<?php

use App\Models\Order;
use App\Services\Exchange1C\OrderExportHandler;

it('includes bonus requisites in order export', function () {
    $order = new Order();
    $order->forceFill([
        'id' => 1, 'status' => 'Собран', 'customer_name' => 'Иван',
        'customer_phone' => '+79990000000', 'bonus_earned' => 30, 'bonus_spent' => 120,
    ]);

    $dom = new \DOMDocument('1.0', 'UTF-8');
    $handler = new OrderExportHandler();
    $ref = new ReflectionMethod($handler, 'buildRequisites');
    $ref->setAccessible(true);
    /** @var \DOMElement $node */
    $node = $ref->invoke($handler, $dom, $order);
    $dom->appendChild($node);
    $xml = $dom->saveXML();

    expect($xml)->toContain('Начислено баллов')
        ->and($xml)->toContain('30')
        ->and($xml)->toContain('Списано баллов')
        ->and($xml)->toContain('120');
});

it('includes zero bonus requisites in order export', function () {
    $order = new Order();
    $order->forceFill([
        'id' => 2, 'status' => 'Собран', 'customer_name' => 'Петр',
        'customer_phone' => '+79990000001', 'bonus_earned' => 0, 'bonus_spent' => 0,
    ]);

    $dom = new \DOMDocument('1.0', 'UTF-8');
    $handler = new OrderExportHandler();
    $ref = new ReflectionMethod($handler, 'buildRequisites');
    $ref->setAccessible(true);
    /** @var \DOMElement $node */
    $node = $ref->invoke($handler, $dom, $order);
    $dom->appendChild($node);
    $xml = $dom->saveXML();

    expect($xml)->toContain('Начислено баллов')
        ->and($xml)->toContain('<Значение>0</Значение>')
        ->and($xml)->toContain('Списано баллов');
});
