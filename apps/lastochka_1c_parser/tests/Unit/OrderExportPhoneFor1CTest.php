<?php

declare(strict_types=1);

use App\Services\Exchange1C\OrderExportHandler;

/**
 * Телефон в машинных полях выгрузки заказа в 1С нормализуется к формату «+7…»:
 * выкидываются все нецифровые символы и добавляется один ведущий «+».
 */
function callFormatPhoneFor1C(?string $phone): string
{
    $handler = (new ReflectionClass(OrderExportHandler::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderExportHandler::class, 'formatPhoneFor1C');
    $method->setAccessible(true);

    return $method->invoke($handler, $phone);
}

test('номер с + сохраняет ведущий +', function () {
    expect(callFormatPhoneFor1C('+79111111111'))->toBe('+79111111111');
});

test('номеру без + добавляется ведущий +', function () {
    expect(callFormatPhoneFor1C('79111111111'))->toBe('+79111111111');
});

test('разделители вычищаются, остаётся +<цифры>', function () {
    expect(callFormatPhoneFor1C('+7 911 111-11-11'))->toBe('+79111111111')
        ->and(callFormatPhoneFor1C('8 (911) 111-11-11'))->toBe('+89111111111');
});

test('null и пустая строка дают пустую строку, иначе +', function () {
    expect(callFormatPhoneFor1C(null))->toBe('')
        ->and(callFormatPhoneFor1C(''))->toBe('')
        ->and(callFormatPhoneFor1C('  +7900  '))->toBe('+7900');
});
