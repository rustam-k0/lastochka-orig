<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Exchange1C\CatalogImporter;
use App\Services\Exchange1C\ProductWhitelist;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/test_delprod_' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->tempXmlPath = $this->tempDir . '/import.xml';

    config(['exchange1c.whitelist_enabled' => false]);
    config(['exchange1c.delete_products_enabled' => true]);
});

afterEach(function () {
    if (file_exists($this->tempXmlPath)) {
        unlink($this->tempXmlPath);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

/**
 * Каталог из одного товара с заданным значением реквизита DelProd.
 */
function delProdCatalogXml(string $delProd, string $name = 'Пряники Домашние 5кг'): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Каталог>
        <Товары>
            <Товар>
                <Ид>guid-delprod-1</Ид>
                <Наименование>{$name}</Наименование>
                <Артикул>ART-1</Артикул>
                <Штрихкод>4600000000001</Штрихкод>
                <ЗначенияРеквизитов>
                    <ЗначениеРеквизита>
                        <Наименование>Код</Наименование>
                        <Значение>22427</Значение>
                    </ЗначениеРеквизита>
                    <ЗначениеРеквизита>
                        <Наименование>DelProd</Наименование>
                        <Значение>{$delProd}</Значение>
                    </ЗначениеРеквизита>
                </ЗначенияРеквизитов>
            </Товар>
        </Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;
}

function importDelProdCatalog(string $xml, string $path): array
{
    file_put_contents($path, $xml);

    return (new CatalogImporter(new ProductWhitelist(), 1))->import($path);
}

test('повторный импорт мягко удалённого товара не падает на UNIQUE', function () {
    importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);

    $product = Product::where('external_id', 'guid-delprod-1')->firstOrFail();
    $product->delete();

    // Тот же товар приходит снова: updateOrCreate без withTrashed сделал бы
    // INSERT и упал на UNIQUE (external_id, shop_id).
    importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);

    expect(Product::withTrashed()->where('external_id', 'guid-delprod-1')->count())->toBe(1);
});

test('DelProd=true мягко удаляет существующий товар', function () {
    importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);

    expect(Product::where('external_id', 'guid-delprod-1')->exists())->toBeTrue();

    $result = importDelProdCatalog(delProdCatalogXml('true'), $this->tempXmlPath);

    expect($result['deleted'])->toBe(1);
    expect(Product::where('external_id', 'guid-delprod-1')->exists())->toBeFalse();

    $trashed = Product::withTrashed()->where('external_id', 'guid-delprod-1')->first();
    expect($trashed)->not->toBeNull();
    expect($trashed->deleted_at)->not->toBeNull();
});

test('DelProd=true по несуществующему товару ничего не создаёт и не падает', function () {
    $result = importDelProdCatalog(delProdCatalogXml('true'), $this->tempXmlPath);

    expect($result['deleted'])->toBe(0);
    expect($result['products'])->toBe(0);
    expect(Product::withTrashed()->count())->toBe(0);
});

test('повторный DelProd=true по уже удалённому товару идемпотентен', function () {
    importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);
    importDelProdCatalog(delProdCatalogXml('true'), $this->tempXmlPath);

    $result = importDelProdCatalog(delProdCatalogXml('true'), $this->tempXmlPath);

    expect($result['deleted'])->toBe(0);
    expect(Product::withTrashed()->where('external_id', 'guid-delprod-1')->count())->toBe(1);
});

test('выключенный ONEC_DELETE_PRODUCTS_ENABLED не даёт удалить товар', function () {
    importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);

    config(['exchange1c.delete_products_enabled' => false]);

    $result = importDelProdCatalog(delProdCatalogXml('true'), $this->tempXmlPath);

    expect($result['deleted'])->toBe(0);
    expect(Product::where('external_id', 'guid-delprod-1')->exists())->toBeTrue();
});

test('товар с DelProd=true не считается импортированным', function () {
    importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);

    $result = importDelProdCatalog(delProdCatalogXml('true'), $this->tempXmlPath);

    expect($result['products'])->toBe(0);
});

test('DelProd=false восстанавливает ранее удалённый товар', function () {
    importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);
    importDelProdCatalog(delProdCatalogXml('true'), $this->tempXmlPath);

    expect(Product::where('external_id', 'guid-delprod-1')->exists())->toBeFalse();

    $result = importDelProdCatalog(delProdCatalogXml('false', 'Пряники Домашние 5кг NEW'), $this->tempXmlPath);

    expect($result['restored'])->toBe(1);

    $product = Product::where('external_id', 'guid-delprod-1')->first();
    expect($product)->not->toBeNull();
    expect($product->deleted_at)->toBeNull();
    // Восстановление идёт вместе с обычным обновлением полей.
    expect($product->name)->toBe('Пряники Домашние 5кг NEW');
});

test('обычный импорт живого товара не увеличивает счётчик восстановлений', function () {
    importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);

    $result = importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);

    expect($result['restored'])->toBe(0);
});

/**
 * Перерегистрировать outbox-observer'ы для текущего экземпляра приложения.
 *
 * RabbitMQEventsServiceProvider::$observersRegistered — static bool на весь
 * PHP-процесс, а Pest пересоздаёт приложение на каждый тест. Без сброса флага
 * слушатели остаются висеть на диспетчере ПЕРВОГО теста процесса, и любой
 * последующий тест на event_outbox зелёный в изоляции, но пустой в сьюте.
 */
function reregisterOutboxObservers(): void
{
    (new ReflectionProperty(
        \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
        'observersRegistered'
    ))->setValue(null, false);

    (new \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider(app()))->boot();
}

test('удаление товара кладёт в outbox ровно одно событие deleted с заполненным deleted_at', function () {
    config()->set('rabbitmq-events.enabled', true);
    reregisterOutboxObservers();

    importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);

    \Comrades\RabbitMQEvents\Models\EventOutbox::query()->delete();

    importDelProdCatalog(delProdCatalogXml('true'), $this->tempXmlPath);

    $events = \Comrades\RabbitMQEvents\Models\EventOutbox::all();

    expect($events)->toHaveCount(1);
    expect($events->first()->event_type)->toBe('deleted');
    expect($events->first()->payload['deleted_at'])->not->toBeNull();
});

test('восстановление товара кладёт в outbox событие с deleted_at = null', function () {
    config()->set('rabbitmq-events.enabled', true);
    reregisterOutboxObservers();

    importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);
    importDelProdCatalog(delProdCatalogXml('true'), $this->tempXmlPath);

    \Comrades\RabbitMQEvents\Models\EventOutbox::query()->delete();

    importDelProdCatalog(delProdCatalogXml('false'), $this->tempXmlPath);

    $events = \Comrades\RabbitMQEvents\Models\EventOutbox::all();

    expect($events)->toHaveCount(1);
    expect($events->first()->event_type)->toBe('updated');
    expect($events->first()->payload['deleted_at'])->toBeNull();
});
