<?php

declare(strict_types=1);

use App\Services\Exchange1C\CatalogImporter;

function makeProductXmlForBarcodes(string $innerXml): SimpleXMLElement
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Товар>
    <Ид>4fab72b1-4563-11f1-a439-d85ed320714a</Ид>
    <Наименование>Творожок Растишка</Наименование>
    {$innerXml}
</Товар>
XML;

    return new SimpleXMLElement($xml);
}

test('parseBarcodes reads Штрихкоды container (CommerceML 2.10)', function () {
    $item = makeProductXmlForBarcodes(<<<XML
    <Штрихкоды>
        <Штрихкод>4600605018903</Штрихкод>
    </Штрихкоды>
    XML);

    expect(CatalogImporter::parseBarcodes($item))->toBe(['4600605018903']);
});

test('parseBarcodes reads several Штрихкод inside container', function () {
    $item = makeProductXmlForBarcodes(<<<XML
    <Штрихкоды>
        <Штрихкод>4600605018903</Штрихкод>
        <Штрихкод>2100100061371</Штрихкод>
    </Штрихкоды>
    XML);

    expect(CatalogImporter::parseBarcodes($item))->toBe(['4600605018903', '2100100061371']);
});

test('parseBarcodes reads ШтрихКоды container (alternative casing)', function () {
    $item = makeProductXmlForBarcodes(<<<XML
    <ШтрихКоды>
        <ШтрихКод>4600605018903</ШтрихКод>
    </ШтрихКоды>
    XML);

    expect(CatalogImporter::parseBarcodes($item))->toBe(['4600605018903']);
});

test('parseBarcodes trims whitespace inside container', function () {
    $item = makeProductXmlForBarcodes(<<<XML
    <Штрихкоды>
        <Штрихкод>  4600605018903  </Штрихкод>
    </Штрихкоды>
    XML);

    expect(CatalogImporter::parseBarcodes($item))->toBe(['4600605018903']);
});

test('parseBarcodes skips empty Штрихкод inside container', function () {
    $item = makeProductXmlForBarcodes(<<<XML
    <Штрихкоды>
        <Штрихкод></Штрихкод>
    </Штрихкоды>
    XML);

    expect(CatalogImporter::parseBarcodes($item))->toBe([]);
});

test('parseBarcodes still reads flat Штрихкод element (legacy 1C format)', function () {
    $item = makeProductXmlForBarcodes('<Штрихкод>4600605018903</Штрихкод>');

    expect(CatalogImporter::parseBarcodes($item))->toBe(['4600605018903']);
});

test('parseBarcodes still reads flat ШтрихКод element (alternative casing)', function () {
    $item = makeProductXmlForBarcodes('<ШтрихКод>4600605018903</ШтрихКод>');

    expect(CatalogImporter::parseBarcodes($item))->toBe(['4600605018903']);
});

test('parseBarcodes reads Штрихкод requisite', function () {
    $item = makeProductXmlForBarcodes(<<<XML
    <ЗначенияРеквизитов>
        <ЗначениеРеквизита>
            <Наименование>Штрихкод</Наименование>
            <Значение>4600605018903</Значение>
        </ЗначениеРеквизита>
    </ЗначенияРеквизитов>
    XML);

    expect(CatalogImporter::parseBarcodes($item))->toBe(['4600605018903']);
});

test('parseBarcodes deduplicates the same barcode from container and requisite', function () {
    $item = makeProductXmlForBarcodes(<<<XML
    <Штрихкоды>
        <Штрихкод>4600605018903</Штрихкод>
    </Штрихкоды>
    <ЗначенияРеквизитов>
        <ЗначениеРеквизита>
            <Наименование>Штрихкод</Наименование>
            <Значение>4600605018903</Значение>
        </ЗначениеРеквизита>
    </ЗначенияРеквизитов>
    XML);

    expect(CatalogImporter::parseBarcodes($item))->toBe(['4600605018903']);
});

test('parseBarcodes returns empty array when 1C sent no barcodes', function () {
    $item = makeProductXmlForBarcodes('<Артикул>87490</Артикул>');

    expect(CatalogImporter::parseBarcodes($item))->toBe([]);
});
