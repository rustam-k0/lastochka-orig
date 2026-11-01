<?php

declare(strict_types=1);

use App\Services\Exchange1C\CatalogImporter;

/**
 * Тесты адаптивного парсинга НДС из двух источников CommerceML:
 *  - старый формат: реквизит <ЗначениеРеквизита><Наименование>НДС</Наименование><Значение>20</Значение>
 *  - новый формат (2.10): налоговый узел <СтавкиНалогов><СтавкаНалога><Наименование>НДС</Наименование><Ставка>20</Ставка>
 */
function makeNdsProductXml(string $innerXml): SimpleXMLElement
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Товар>
    <Ид>3a71b117-064e-11e6-aacc-581122996da2</Ид>
    <Наименование>Гель д/рук хималая 50мл антибактериальный</Наименование>
    {$innerXml}
</Товар>
XML;

    return new SimpleXMLElement($xml);
}

function callParseAttributesForNds(SimpleXMLElement $item): array
{
    // newInstanceWithoutConstructor — конструктор создаёт ProductWhitelist,
    // который читает config() и требует загруженного app. parseAttributes
    // от состояния не зависит, поэтому app тут не нужен.
    $importer = (new ReflectionClass(CatalogImporter::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CatalogImporter::class, 'parseAttributes');
    $method->setAccessible(true);

    return $method->invoke($importer, $item);
}

test('НДС берётся из налогового узла СтавкиНалогов (новый формат 2.10)', function () {
    $item = makeNdsProductXml(<<<XML
        <СтавкиНалогов>
            <СтавкаНалога>
                <Наименование>НДС</Наименование>
                <Ставка>20</Ставка>
            </СтавкаНалога>
        </СтавкиНалогов>
        XML);

    $attributes = callParseAttributesForNds($item);

    expect($attributes['nds'])->toBe('20');
});

test('НДС берётся из реквизита (старый формат)', function () {
    $item = makeNdsProductXml(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>НДС</Наименование>
                <Значение>18</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    $attributes = callParseAttributesForNds($item);

    expect($attributes['nds'])->toBe('18');
});

test('алиас «Ставка НДС» в реквизите тоже распознаётся', function () {
    $item = makeNdsProductXml(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Ставка НДС</Наименование>
                <Значение>10</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    $attributes = callParseAttributesForNds($item);

    expect($attributes['nds'])->toBe('10');
});

test('при наличии обоих источников приоритет у реквизита (обратная совместимость)', function () {
    $item = makeNdsProductXml(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>НДС</Наименование>
                <Значение>10</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        <СтавкиНалогов>
            <СтавкаНалога>
                <Наименование>НДС</Наименование>
                <Ставка>20</Ставка>
            </СтавкаНалога>
        </СтавкиНалогов>
        XML);

    $attributes = callParseAttributesForNds($item);

    // Явно заданный реквизит «10» приоритетнее налогового узла «20».
    expect($attributes['nds'])->toBe('10');
});

test('текстовое значение ставки («Без налога») доходит как есть', function () {
    $item = makeNdsProductXml(<<<XML
        <СтавкиНалогов>
            <СтавкаНалога>
                <Наименование>НДС</Наименование>
                <Ставка>Без налога</Ставка>
            </СтавкаНалога>
        </СтавкиНалогов>
        XML);

    $attributes = callParseAttributesForNds($item);

    expect($attributes['nds'])->toBe('Без налога');
});

test('налог, отличный от НДС, в nds не пишется', function () {
    $item = makeNdsProductXml(<<<XML
        <СтавкиНалогов>
            <СтавкаНалога>
                <Наименование>Налог с продаж</Наименование>
                <Ставка>5</Ставка>
            </СтавкаНалога>
        </СтавкиНалогов>
        XML);

    $attributes = callParseAttributesForNds($item);

    expect($attributes)->not->toHaveKey('nds');
});

test('пустая ставка НДС не создаёт ключ nds', function () {
    $item = makeNdsProductXml(<<<XML
        <СтавкиНалогов>
            <СтавкаНалога>
                <Наименование>НДС</Наименование>
                <Ставка></Ставка>
            </СтавкаНалога>
        </СтавкиНалогов>
        XML);

    $attributes = callParseAttributesForNds($item);

    expect($attributes)->not->toHaveKey('nds');
});

test('без налоговых данных ключ nds отсутствует', function () {
    $item = makeNdsProductXml('<БазоваяЕдиница>796</БазоваяЕдиница>');

    $attributes = callParseAttributesForNds($item);

    expect($attributes)->not->toHaveKey('nds');
});
