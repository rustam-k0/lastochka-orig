<?php

declare(strict_types=1);

use App\Services\Exchange1C\CatalogImporter;

function makeProductXmlForCode(string $innerXml): SimpleXMLElement
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

test('parseCode reads Код requisite', function () {
    $item = makeProductXmlForCode(<<<XML
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

    // Разделитель разрядов снимается: в базе код нужен слитным, сборщик вводит
    // цифры с ценника без пробела.
    expect(CatalogImporter::parseCode($item))->toBe('89109');
});

test('parseCode trims whitespace', function () {
    $item = makeProductXmlForCode(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Код</Наименование>
                <Значение>  00042  </Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parseCode($item))->toBe('00042');
});

test('parseCode prefers top-level Код element over requisite', function () {
    $item = makeProductXmlForCode(<<<XML
        <Код>TOP-001</Код>
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Код</Наименование>
                <Значение>REQ-002</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parseCode($item))->toBe('TOP-001');
});

test('parseCode is independent from Артикул (returns Код even when Артикул is filled)', function () {
    $item = makeProductXmlForCode(<<<XML
        <Артикул>ABC-123</Артикул>
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Код</Наименование>
                <Значение>00042</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parseCode($item))->toBe('00042');
});

test('parseCode returns null when Код is empty', function () {
    $item = makeProductXmlForCode(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Код</Наименование>
                <Значение/>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parseCode($item))->toBeNull();
});

test('parseCode returns null when no Код present', function () {
    $item = makeProductXmlForCode('<Артикул>ABC-123</Артикул>');

    expect(CatalogImporter::parseCode($item))->toBeNull();
});

test('parseCode strips non-breaking space between digit groups', function () {
    // 1С форматирует числовой код разрядами через NBSP: «172 484».
    $item = makeProductXmlForCode("<Код>172\u{A0}484</Код>");

    expect(CatalogImporter::parseCode($item))->toBe('172484');
});

test('parseCode strips separators in Код requisite fallback', function () {
    $item = makeProductXmlForCode(
        "<ЗначенияРеквизитов><ЗначениеРеквизита><Наименование>Код</Наименование>"
        . "<Значение>27\u{A0}386</Значение></ЗначениеРеквизита></ЗначенияРеквизитов>"
    );

    expect(CatalogImporter::parseCode($item))->toBe('27386');
});

test('parseCode keeps inner space in non numeric code', function () {
    // Буквенный код — внутренний пробел несёт смысл, режем только края.
    $item = makeProductXmlForCode('<Код>  ABC 123  </Код>');

    expect(CatalogImporter::parseCode($item))->toBe('ABC 123');
});
