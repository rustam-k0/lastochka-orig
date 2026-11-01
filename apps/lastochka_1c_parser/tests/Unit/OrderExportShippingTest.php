<?php

declare(strict_types=1);

use App\Models\Order;
use App\Services\Exchange1C\OrderExportHandler;
use Tests\TestCase;

uses(TestCase::class);

function invokeExport(string $method, array $args)
{
    $handler = new OrderExportHandler();
    $m = new ReflectionMethod(OrderExportHandler::class, $method);
    $m->setAccessible(true);

    return $m->invoke($handler, ...$args);
}

function buildExportXml(string $method, Order $order): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $el = invokeExport($method, [$dom, $order]);
    $dom->appendChild($el);

    return $dom->saveXML();
}

test('resolveShippingMethodLabel maps known methods', function () {
    expect(invokeExport('resolveShippingMethodLabel', ['pickup']))->toBe('Самовывоз')
        ->and(invokeExport('resolveShippingMethodLabel', ['courier']))->toBe('Доставка курьером')
        ->and(invokeExport('resolveShippingMethodLabel', ['cdek']))->toBe('СДЭК')
        ->and(invokeExport('resolveShippingMethodLabel', ['russian_post']))->toBe('Почта России')
        ->and(invokeExport('resolveShippingMethodLabel', ['yandex_delivery']))->toBe('Яндекс Доставка');
});

test('resolveShippingMethodLabel: unknown non-empty → Доставка, empty/null → null', function () {
    expect(invokeExport('resolveShippingMethodLabel', ['some_new_carrier']))->toBe('Доставка')
        ->and(invokeExport('resolveShippingMethodLabel', [null]))->toBeNull()
        ->and(invokeExport('resolveShippingMethodLabel', ['  ']))->toBeNull();
});

test('resolvePaymentTypeLabel maps cash/card, unknown→raw, empty→null', function () {
    expect(invokeExport('resolvePaymentTypeLabel', ['cash']))->toBe('Наличные')
        ->and(invokeExport('resolvePaymentTypeLabel', ['card']))->toBe('Картой')
        ->and(invokeExport('resolvePaymentTypeLabel', ['sbp']))->toBe('sbp')
        ->and(invokeExport('resolvePaymentTypeLabel', [null]))->toBeNull()
        ->and(invokeExport('resolvePaymentTypeLabel', ['']))->toBeNull();
});

test('buildRequisites includes «Способ доставки» and «Способ оплаты»', function () {
    $order = new Order([
        'status' => 'Собран',
        'shipping_method' => 'pickup',
        'payment_type' => 'card',
        'customer_phone' => '+79990001122',
    ]);

    $xml = buildExportXml('buildRequisites', $order);

    expect($xml)->toContain('Способ доставки')->toContain('Самовывоз')
        ->toContain('Способ оплаты')->toContain('Картой');
});

test('buildRequisites omits «Способ доставки»/«Способ оплаты» when empty', function () {
    $order = new Order(['status' => 'Собран', 'customer_phone' => '+79990001122']);

    $xml = buildExportXml('buildRequisites', $order);

    expect($xml)->not->toContain('Способ доставки')
        ->and($xml)->not->toContain('Способ оплаты');
});

test('buildRequisites includes «Телефон» requisite with customer phone', function () {
    $order = new Order(['status' => 'Собран', 'customer_phone' => '+79990001122']);

    $xml = buildExportXml('buildRequisites', $order);

    expect($xml)->toContain('<Наименование>Телефон</Наименование>')
        ->toContain('<Значение>+79990001122</Значение>');
});

test('buildRequisites omits «Телефон» requisite when phone empty', function () {
    $order = new Order(['status' => 'Собран']);

    $xml = buildExportXml('buildRequisites', $order);

    expect($xml)->not->toContain('<Наименование>Телефон</Наименование>');
});

test('buildContragent emits АдресРегистрации with composed address', function () {
    $order = new Order([
        'customer_name' => 'Иван',
        'customer_phone' => '+79990001122',
        'delivery_address' => 'ул. Садовая, 1, кв. 9',
    ]);

    $xml = buildExportXml('buildContragent', $order);

    expect($xml)->toContain('АдресРегистрации')->toContain('ул. Садовая, 1, кв. 9');
});

test('buildContragent omits АдресРегистрации when no delivery_address', function () {
    $order = new Order(['customer_name' => 'Иван', 'customer_phone' => '+79990001122']);

    $xml = buildExportXml('buildContragent', $order);

    expect($xml)->not->toContain('АдресРегистрации');
});

test('buildContragent выгружает телефон контактом «ТелефонРабочий» с ведущим +', function () {
    $order = new Order([
        'customer_name' => 'Иван Иванов',
        'customer_phone' => '79990001122',
        'customer_email' => 'ivan@example.com',
    ]);

    $xml = buildExportXml('buildContragent', $order);

    // Контакт телефона — тип «ТелефонРабочий», значение с ведущим «+».
    expect($xml)->toContain('<Тип>ТелефонРабочий</Тип>')
        ->toContain('<Значение>+79990001122</Значение>')
        // тег <Телефон> контрагента тоже с «+»
        ->toContain('<Телефон>+79990001122</Телефон>')
        // почта по-прежнему отдельным контактом «Почта»
        ->toContain('<Тип>Почта</Тип>')
        // старого типа контакта «Телефон» быть не должно
        ->and($xml)->not->toContain('<Тип>Телефон</Тип>');
});

test('buildRequisites includes «Адрес доставки» requisite with delivery address', function () {
    $order = new Order([
        'status' => 'Собран',
        'customer_phone' => '+79990001122',
        'delivery_address' => 'Россия, Нальчик, ул Вологирова, д. 105',
    ]);

    $xml = buildExportXml('buildRequisites', $order);

    expect($xml)->toContain('<Наименование>Адрес доставки</Наименование>')
        ->toContain('<Значение>Россия, Нальчик, ул Вологирова, д. 105</Значение>');
});

test('buildRequisites omits «Адрес доставки» requisite when address empty', function () {
    $order = new Order(['status' => 'Собран', 'customer_phone' => '+79990001122']);

    $xml = buildExportXml('buildRequisites', $order);

    expect($xml)->not->toContain('<Наименование>Адрес доставки</Наименование>');
});
