<?php

declare(strict_types=1);

use App\Services\Exchange1C\CatalogImporter;

function makeProductXmlForDelProd(string $innerXml): SimpleXMLElement
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Товар>
    <Ид>78e2a5b4-01b3-11e8-8013-001517b3ea47</Ид>
    <Наименование>Пряники Домашние 5кг</Наименование>
    {$innerXml}
</Товар>
XML;

    return new SimpleXMLElement($xml);
}

function delProdRequisite(string $value): string
{
    return <<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>ТипНоменклатуры</Наименование>
                <Значение>Товар</Значение>
            </ЗначениеРеквизита>
            <ЗначениеРеквизита>
                <Наименование>DelProd</Наименование>
                <Значение>{$value}</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML;
}

test('parseDelProd возвращает true для значения true', function () {
    $item = makeProductXmlForDelProd(delProdRequisite('true'));

    expect(CatalogImporter::parseDelProd($item))->toBeTrue();
});

test('parseDelProd возвращает false для значения false', function () {
    $item = makeProductXmlForDelProd(delProdRequisite('false'));

    expect(CatalogImporter::parseDelProd($item))->toBeFalse();
});

test('parseDelProd не зависит от регистра значения', function () {
    expect(CatalogImporter::parseDelProd(makeProductXmlForDelProd(delProdRequisite('True'))))->toBeTrue();
    expect(CatalogImporter::parseDelProd(makeProductXmlForDelProd(delProdRequisite('TRUE'))))->toBeTrue();
});

test('parseDelProd обрезает пробелы вокруг значения', function () {
    $item = makeProductXmlForDelProd(delProdRequisite('  true  '));

    expect(CatalogImporter::parseDelProd($item))->toBeTrue();
});

test('parseDelProd понимает значения 1 и да', function () {
    expect(CatalogImporter::parseDelProd(makeProductXmlForDelProd(delProdRequisite('1'))))->toBeTrue();
    expect(CatalogImporter::parseDelProd(makeProductXmlForDelProd(delProdRequisite('Да'))))->toBeTrue();
});

test('parseDelProd возвращает false для пустого значения', function () {
    $item = makeProductXmlForDelProd(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>DelProd</Наименование>
                <Значение/>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parseDelProd($item))->toBeFalse();
});

test('parseDelProd возвращает false когда реквизита нет вообще', function () {
    $item = makeProductXmlForDelProd(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Код</Наименование>
                <Значение>22 427</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(CatalogImporter::parseDelProd($item))->toBeFalse();
});

test('parseDelProd возвращает false когда узла ЗначенияРеквизитов нет', function () {
    $item = makeProductXmlForDelProd('<Артикул>ART-1</Артикул>');

    expect(CatalogImporter::parseDelProd($item))->toBeFalse();
});
