<?php

declare(strict_types=1);

use App\Models\CatalogImportChunk;
use App\Models\ExchangeLog;
use App\Services\Exchange1C\CatalogImporter;
use App\Services\Exchange1C\ProductWhitelist;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Импортёр каталога фиксирует состав каждого файла выгрузки (все Ид товаров)
 * в catalog_import_chunks, чтобы шаг deactivate от 1С мог вычислить, какие
 * товары в полную выгрузку не попали. Пишется только при включённом per-shop
 * флаге deactivate_missing_enabled и только когда есть ExchangeLog файла.
 */
beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/test_seen_chunk_' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->tempXmlPath = $this->tempDir . '/import.xml';
    $this->tempCsvPath = $this->tempDir . '/whitelist.csv';

    config(['exchange1c.whitelist_enabled' => false]);
    config(['exchange1c.delete_products_enabled' => true]);
    config(['exchange1c.deactivate_missing_enabled' => true]);
});

afterEach(function () {
    foreach ([$this->tempXmlPath, $this->tempCsvPath] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

/**
 * Каталог из трёх товаров: обычный, с DelProd=true и со штрихкодом вне whitelist.
 */
function seenChunkCatalogXml(): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Каталог>
        <Товары>
            <Товар>
                <Ид>guid-seen-1</Ид>
                <Наименование>Обычный товар</Наименование>
                <Штрихкод>4600000000001</Штрихкод>
            </Товар>
            <Товар>
                <Ид>guid-seen-2</Ид>
                <Наименование>Удалённый по DelProd</Наименование>
                <Штрихкод>4600000000002</Штрихкод>
                <ЗначенияРеквизитов>
                    <ЗначениеРеквизита>
                        <Наименование>DelProd</Наименование>
                        <Значение>true</Значение>
                    </ЗначениеРеквизита>
                </ЗначенияРеквизитов>
            </Товар>
            <Товар>
                <Ид>guid-seen-3#char-1</Ид>
                <Наименование>Вне whitelist, с характеристикой</Наименование>
                <Штрихкод>4600000000003</Штрихкод>
            </Товар>
        </Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;
}

function importSeenChunkCatalog(string $path, ?ExchangeLog $log, ?ProductWhitelist $whitelist = null): array
{
    file_put_contents($path, seenChunkCatalogXml());

    return (new CatalogImporter($whitelist ?? new ProductWhitelist(), 1))->import($path, $log);
}

test('при включённом флаге импорт пишет чанк со всеми Ид файла', function () {
    makeIntegrationConfig(['shop_id' => 1, 'deactivate_missing_enabled' => true]);
    $log = ExchangeLog::start(ExchangeLog::TYPE_CATALOG_IMPORT);
    $log->update(['shop_id' => 1]);

    importSeenChunkCatalog($this->tempXmlPath, $log);

    $chunk = CatalogImportChunk::where('shop_id', 1)->first();
    expect($chunk)->not->toBeNull();
    expect($chunk->exchange_log_id)->toBe($log->id);
    // Состав файла = все Ид, включая DelProd-товар и Ид с характеристикой (без #...).
    expect($chunk->external_ids)->toBe(['guid-seen-1', 'guid-seen-2', 'guid-seen-3']);
    expect($chunk->products_count)->toBe(3);
});

test('товар, отсечённый whitelist, всё равно входит в состав выгрузки', function () {
    makeIntegrationConfig(['shop_id' => 1, 'deactivate_missing_enabled' => true]);
    $log = ExchangeLog::start(ExchangeLog::TYPE_CATALOG_IMPORT);
    $log->update(['shop_id' => 1]);

    file_put_contents(
        $this->tempCsvPath,
        "№,Наименование,Бренд,Цена,Единица измерения,Штрихкод\n1,Обычный товар,-,\"10,0\",шт,4600000000001\n"
    );

    $result = importSeenChunkCatalog($this->tempXmlPath, $log, new ProductWhitelist($this->tempCsvPath));

    // Отсечён guid-seen-3 (штрихкода нет в CSV); guid-seen-2 удалён по DelProd до whitelist.
    expect($result['skipped_whitelist'])->toBe(1);
    expect(CatalogImportChunk::first()->external_ids)->toContain('guid-seen-3');
});

test('при выключенном per-shop флаге чанк не пишется', function () {
    makeIntegrationConfig(['shop_id' => 1, 'deactivate_missing_enabled' => false]);
    $log = ExchangeLog::start(ExchangeLog::TYPE_CATALOG_IMPORT);
    $log->update(['shop_id' => 1]);

    importSeenChunkCatalog($this->tempXmlPath, $log);

    expect(CatalogImportChunk::count())->toBe(0);
});

test('при выключенном аварийном ONEC_DEACTIVATE_MISSING_ENABLED чанк не пишется', function () {
    makeIntegrationConfig(['shop_id' => 1, 'deactivate_missing_enabled' => true]);
    config(['exchange1c.deactivate_missing_enabled' => false]);
    $log = ExchangeLog::start(ExchangeLog::TYPE_CATALOG_IMPORT);
    $log->update(['shop_id' => 1]);

    importSeenChunkCatalog($this->tempXmlPath, $log);

    expect(CatalogImportChunk::count())->toBe(0);
});

test('без ExchangeLog файла чанк не пишется', function () {
    makeIntegrationConfig(['shop_id' => 1, 'deactivate_missing_enabled' => true]);

    importSeenChunkCatalog($this->tempXmlPath, null);

    expect(CatalogImportChunk::count())->toBe(0);
});

test('при записи чанка устаревшие чанки магазина удаляются, свежие и чужие остаются', function () {
    config(['exchange1c.chunk_retention_hours' => 24]);
    makeIntegrationConfig(['shop_id' => 1, 'deactivate_missing_enabled' => true]);

    $stale = CatalogImportChunk::create(['shop_id' => 1, 'exchange_log_id' => 1, 'external_ids' => ['x'], 'products_count' => 1]);
    CatalogImportChunk::where('id', $stale->id)->update(['created_at' => now()->subHours(25)]);
    $fresh = CatalogImportChunk::create(['shop_id' => 1, 'exchange_log_id' => 2, 'external_ids' => ['y'], 'products_count' => 1]);
    CatalogImportChunk::where('id', $fresh->id)->update(['created_at' => now()->subHours(23)]);
    $foreign = CatalogImportChunk::create(['shop_id' => 2, 'exchange_log_id' => 3, 'external_ids' => ['z'], 'products_count' => 1]);
    CatalogImportChunk::where('id', $foreign->id)->update(['created_at' => now()->subHours(48)]);

    $log = ExchangeLog::start(ExchangeLog::TYPE_CATALOG_IMPORT);
    $log->update(['shop_id' => 1]);
    importSeenChunkCatalog($this->tempXmlPath, $log);

    expect(CatalogImportChunk::find($stale->id))->toBeNull();
    expect(CatalogImportChunk::find($fresh->id))->not->toBeNull();
    expect(CatalogImportChunk::find($foreign->id))->not->toBeNull();
    expect(CatalogImportChunk::where('exchange_log_id', $log->id)->exists())->toBeTrue();
});
