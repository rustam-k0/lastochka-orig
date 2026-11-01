<?php

declare(strict_types=1);

use App\Services\Exchange1C\CatalogImporter;

function makeProductXml(string $innerXml): SimpleXMLElement
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Товар>
    <Ид>3a71b117-064e-11e6-aacc-581122996da2</Ид>
    <Наименование>Шампунь Pantene</Наименование>
    {$innerXml}
</Товар>
XML;

    return new SimpleXMLElement($xml);
}

test('parseSku returns Артикул when filled', function () {
    $item = makeProductXml('<Артикул>ABC-123</Артикул>');

    expect(CatalogImporter::parseSku($item))->toBe('ABC-123');
});

test('parseSku trims Артикул whitespace', function () {
    $item = makeProductXml('<Артикул>  ABC-123  </Артикул>');

    expect(CatalogImporter::parseSku($item))->toBe('ABC-123');
});

test('parseSku falls back to Код requisite when Артикул is empty', function () {
    $item = makeProductXml(<<<XML
        <Артикул/>
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>ТипНоменклатуры</Наименование>
                <Значение>Товар</Значение>
            </ЗначениеРеквизита>
            <ЗначениеРеквизита>
                <Наименование>Код</Наименование>
                <Значение>89 109</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parseSku($item))->toBe('89 109');
});

test('parseSku falls back to Код requisite when Артикул tag is missing', function () {
    $item = makeProductXml(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Код</Наименование>
                <Значение>00042</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parseSku($item))->toBe('00042');
});

test('parseSku returns null when both Артикул and Код are empty', function () {
    $item = makeProductXml(<<<XML
        <Артикул/>
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Код</Наименование>
                <Значение/>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parseSku($item))->toBeNull();
});

test('parseSku returns null when no requisites and no Артикул', function () {
    $item = makeProductXml('');

    expect(CatalogImporter::parseSku($item))->toBeNull();
});

test('parseSku ignores other requisites named not Код', function () {
    $item = makeProductXml(<<<XML
        <Артикул/>
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Полное наименование</Наименование>
                <Значение>Шампунь Pantene Aqua Light 400 мл.</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parseSku($item))->toBeNull();
});
