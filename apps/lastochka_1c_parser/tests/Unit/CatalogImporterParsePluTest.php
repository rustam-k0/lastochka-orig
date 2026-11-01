<?php

declare(strict_types=1);

use App\Services\Exchange1C\CatalogImporter;

function makeProductXmlForPlu(string $innerXml): SimpleXMLElement
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Товар>
    <Ид>3a71b117-064e-11e6-aacc-581122996da2</Ид>
    <Наименование>Яблоки Голден</Наименование>
    {$innerXml}
</Товар>
XML;

    return new SimpleXMLElement($xml);
}

test('parsePlu reads top-level ПЛУ element', function () {
    $item = makeProductXmlForPlu('<ПЛУ>4126</ПЛУ>');

    expect(CatalogImporter::parsePlu($item))->toBe('4126');
});

test('parsePlu reads top-level PLU element (latin)', function () {
    $item = makeProductXmlForPlu('<PLU>4131</PLU>');

    expect(CatalogImporter::parsePlu($item))->toBe('4131');
});

test('parsePlu trims whitespace', function () {
    $item = makeProductXmlForPlu('<ПЛУ>  4126  </ПЛУ>');

    expect(CatalogImporter::parsePlu($item))->toBe('4126');
});

test('parsePlu reads ПЛУ requisite when top-level element absent', function () {
    $item = makeProductXmlForPlu(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>ТипНоменклатуры</Наименование>
                <Значение>Товар</Значение>
            </ЗначениеРеквизита>
            <ЗначениеРеквизита>
                <Наименование>ПЛУ</Наименование>
                <Значение>4126</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parsePlu($item))->toBe('4126');
});

test('parsePlu reads PLU requisite (latin name) when top-level element absent', function () {
    $item = makeProductXmlForPlu(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>PLU</Наименование>
                <Значение>4131</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parsePlu($item))->toBe('4131');
});

test('parsePlu prefers top-level ПЛУ element over requisite', function () {
    $item = makeProductXmlForPlu(<<<XML
        <ПЛУ>4126</ПЛУ>
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>ПЛУ</Наименование>
                <Значение>9999</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parsePlu($item))->toBe('4126');
});

test('parsePlu returns null when ПЛУ requisite is empty', function () {
    $item = makeProductXmlForPlu(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>ПЛУ</Наименование>
                <Значение/>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parsePlu($item))->toBeNull();
});

test('parsePlu returns null when 1С does not send PLU at all', function () {
    $item = makeProductXmlForPlu('<Артикул>ABC-123</Артикул>');

    expect(CatalogImporter::parsePlu($item))->toBeNull();
});
