<?php

use App\Models\Order;
use App\Services\Exchange1C\OrderExportHandler;

function buildRequisitesXml(Order $order): string
{
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $handler = new OrderExportHandler();
    $ref = new ReflectionMethod($handler, 'buildRequisites');
    $ref->setAccessible(true);
    $node = $ref->invoke($handler, $dom, $order);
    $dom->appendChild($node);

    return $dom->saveXML();
}

it('exports all four loyalty requisites including zeros and no-card', function () {
    $order = new Order();
    $order->forceFill([
        'id' => 1, 'status' => 'Собран', 'customer_name' => 'Иван',
        'bonus_earned' => 0, 'bonus_spent' => 0, 'has_card' => false, 'card_number' => null,
    ]);

    $xml = buildRequisitesXml($order);

    expect($xml)->toContain('Начислено баллов')
        ->and($xml)->toContain('Списано баллов')
        ->and($xml)->toContain('Признак наличия карты')
        ->and($xml)->toContain('Нет')
        ->and($xml)->not->toContain('Номер карты');
});

it('exports card number when order has confirmed card', function () {
    $order = new Order();
    $order->forceFill([
        'id' => 2, 'status' => 'Собран', 'customer_name' => 'Иван',
        'bonus_earned' => 30, 'bonus_spent' => 120,
        'has_card' => true, 'card_number' => '07123456',
    ]);

    $xml = buildRequisitesXml($order);

    expect($xml)->toContain('Признак наличия карты')
        ->and($xml)->toContain('>Да<')
        ->and($xml)->toContain('Номер карты')
        ->and($xml)->toContain('07123456');
});

it('card creation order carries Нет + Номер карты + ИД лояльности', function () {
    // Заказ создания карты: в 1С карты ещё нет → признак Нет (сигнал «создай карту»),
    // но номер и ИД лояльности обязательны — по ним 1С заводит карту.
    $order = new Order();
    $order->forceFill([
        'id' => 3, 'status' => 'Собран', 'customer_name' => 'Клиент 07123456',
        'source' => Order::SOURCE_LOYALTY_CARD,
        'has_card' => false, 'card_number' => '07123456', 'loyalty_id' => 'LTY-01ABC',
        'bonus_earned' => 0, 'bonus_spent' => 0,
    ]);

    $xml = buildRequisitesXml($order);

    expect($xml)->toContain('Признак наличия карты')
        ->and($xml)->toContain('>Нет<')
        ->and($xml)->toContain('Номер карты')
        ->and($xml)->toContain('07123456')
        ->and($xml)->toContain('ИД лояльности')
        ->and($xml)->toContain('LTY-01ABC');
});

it('regular order without card omits Номер карты', function () {
    // Контроль: у ОБЫЧНОГО заказа без карты (has_card=false, source!=loyalty_card)
    // «Номер карты» не выгружается — расширенный гейт не задел обычные заказы.
    $order = new Order();
    $order->forceFill([
        'id' => 4, 'status' => 'Собран', 'customer_name' => 'Иван',
        'source' => 'shop',
        'has_card' => false, 'card_number' => null,
        'bonus_earned' => 0, 'bonus_spent' => 0,
    ]);

    $xml = buildRequisitesXml($order);

    expect($xml)->toContain('>Нет<')
        ->and($xml)->not->toContain('Номер карты');
});
