<?php

declare(strict_types=1);

use App\Services\Exchange1C\CatalogImporter;

function makeProductXmlForNutrition(string $innerXml): SimpleXMLElement
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Товар>
    <Ид>3a71b117-064e-11e6-aacc-581122996da2</Ид>
    <Наименование>Булочка с сосиской ласточка</Наименование>
    {$innerXml}
</Товар>
XML;

    return new SimpleXMLElement($xml);
}

function callParseAttributes(SimpleXMLElement $item): array
{
    // newInstanceWithoutConstructor — конструктор CatalogImporter создаёт
    // ProductWhitelist, который читает config() и требует загруженного app.
    // parseAttributes от состояния не зависит, поэтому app тут не нужен.
    $importer = (new ReflectionClass(CatalogImporter::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CatalogImporter::class, 'parseAttributes');
    $method->setAccessible(true);

    return $method->invoke($importer, $item);
}

test('parseAttributes extracts composition and КБЖУ from requisites', function () {
    $item = makeProductXmlForNutrition(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Состав</Наименование>
                <Значение>мука, яйцо, сахар, масло</Значение>
            </ЗначениеРеквизита>
            <ЗначениеРеквизита>
                <Наименование>Калорийность</Наименование>
                <Значение>250</Значение>
            </ЗначениеРеквизита>
            <ЗначениеРеквизита>
                <Наименование>Белки</Наименование>
                <Значение>5.2</Значение>
            </ЗначениеРеквизита>
            <ЗначениеРеквизита>
                <Наименование>Жиры</Наименование>
                <Значение>12</Значение>
            </ЗначениеРеквизита>
            <ЗначениеРеквизита>
                <Наименование>Углеводы</Наименование>
                <Значение>30.5</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    $attributes = callParseAttributes($item);

    expect($attributes['composition'])->toBe('мука, яйцо, сахар, масло')
        ->and($attributes['calories'])->toBe('250')
        ->and($attributes['proteins'])->toBe('5.2')
        ->and($attributes['fats'])->toBe('12')
        ->and($attributes['carbs'])->toBe('30.5');
});

test('parseAttributes accepts КБЖУ synonyms (Ккал, Энергетическая ценность, Белок)', function () {
    $item = makeProductXmlForNutrition(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Ккал</Наименование>
                <Значение>180</Значение>
            </ЗначениеРеквизита>
            <ЗначениеРеквизита>
                <Наименование>Белок</Наименование>
                <Значение>3</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    $attributes = callParseAttributes($item);

    expect($attributes['calories'])->toBe('180')
        ->and($attributes['proteins'])->toBe('3');
});

test('parseAttributes is case-insensitive for cyrillic names', function () {
    $item = makeProductXmlForNutrition(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>СОСТАВ</Наименование>
                <Значение>вода</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    expect(callParseAttributes($item)['composition'])->toBe('вода');
});

test('parseAttributes ignores empty composition/КБЖУ values', function () {
    $item = makeProductXmlForNutrition(<<<XML
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Состав</Наименование>
                <Значение/>
            </ЗначениеРеквизита>
            <ЗначениеРеквизита>
                <Наименование>Калорийность</Наименование>
                <Значение>   </Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    $attributes = callParseAttributes($item);

    expect($attributes)->not->toHaveKey('composition')
        ->and($attributes)->not->toHaveKey('calories');
});

test('parseAttributes reads composition from ЗначенияСвойств too', function () {
    $item = makeProductXmlForNutrition(<<<XML
        <ЗначенияСвойств>
            <ЗначенияСвойства>
                <Наименование>Состав</Наименование>
                <Значение>сахар, какао</Значение>
            </ЗначенияСвойства>
        </ЗначенияСвойств>
        XML);

    expect(callParseAttributes($item)['composition'])->toBe('сахар, какао');
});

test('parseAttributes keeps existing system fields alongside nutrition', function () {
    $item = makeProductXmlForNutrition(<<<XML
        <БазоваяЕдиница Код="166">кг</БазоваяЕдиница>
        <ЗначенияРеквизитов>
            <ЗначениеРеквизита>
                <Наименование>Вес</Наименование>
                <Значение>500</Значение>
            </ЗначениеРеквизита>
            <ЗначениеРеквизита>
                <Наименование>Состав</Наименование>
                <Значение>молоко</Значение>
            </ЗначениеРеквизита>
        </ЗначенияРеквизитов>
        XML);

    $attributes = callParseAttributes($item);

    expect($attributes['unit'])->toBe('кг')
        ->and($attributes['weight'])->toBe('500')
        ->and($attributes['composition'])->toBe('молоко');
});
