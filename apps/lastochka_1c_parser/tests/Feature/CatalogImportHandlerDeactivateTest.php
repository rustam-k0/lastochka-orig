<?php

declare(strict_types=1);

use App\Jobs\DeactivateMissingProductsJob;
use App\Models\CatalogImportChunk;
use App\Models\ExchangeLog;
use App\Models\Product;
use App\Services\Exchange1C\CatalogImportHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mavsan\LaProtocol\Interfaces\Import;
use Mavsan\LaProtocol\Interfaces\ImportBitrix;

uses(RefreshDatabase::class);

/**
 * Команда deactivate от 1С (конец полной выгрузки) доходит до
 * CatalogImportHandler::modeDeactivate через интерфейс ImportBitrix mavsan.
 * Обработчик гейтит её двумя флагами и уводит работу в очередь.
 */
beforeEach(function () {
    config(['exchange1c.deactivate_missing_enabled' => true]);
    app()->instance('current_shop_id', 1);
    app()->instance('current_shop_code', 'shop-1');
});

afterEach(function () {
    app()->forgetInstance('current_shop_id');
    app()->forgetInstance('current_shop_code');
});

test('обработчик реализует ImportBitrix, иначе mavsan не вызовет modeDeactivate', function () {
    expect(new CatalogImportHandler())->toBeInstanceOf(ImportBitrix::class);
});

test('при выключенном per-shop флаге отвечает success без лога и job', function () {
    Bus::fake();
    makeIntegrationConfig(['shop_id' => 1, 'deactivate_missing_enabled' => false]);

    $answer = (new CatalogImportHandler())->modeDeactivate('2026-09-07 09:29:28');

    expect($answer)->toBe(Import::answerSuccess);
    expect(ExchangeLog::where('type', ExchangeLog::TYPE_DEACTIVATE)->count())->toBe(0);
    Bus::assertNotDispatched(DeactivateMissingProductsJob::class);
});

test('при выключенном аварийном ONEC_DEACTIVATE_MISSING_ENABLED отвечает success без job', function () {
    Bus::fake();
    config(['exchange1c.deactivate_missing_enabled' => false]);
    makeIntegrationConfig(['shop_id' => 1, 'deactivate_missing_enabled' => true]);

    $answer = (new CatalogImportHandler())->modeDeactivate('2026-09-07 09:29:28');

    expect($answer)->toBe(Import::answerSuccess);
    Bus::assertNotDispatched(DeactivateMissingProductsJob::class);
});

test('без контекста магазина отвечает success без job', function () {
    Bus::fake();
    app()->forgetInstance('current_shop_id');
    makeIntegrationConfig(['shop_id' => 1, 'deactivate_missing_enabled' => true]);

    $answer = (new CatalogImportHandler())->modeDeactivate('2026-09-07 09:29:28');

    expect($answer)->toBe(Import::answerSuccess);
    Bus::assertNotDispatched(DeactivateMissingProductsJob::class);
});

test('при включённом флаге создаёт лог deactivate по магазину и ставит job в очередь', function () {
    Bus::fake();
    makeIntegrationConfig(['shop_id' => 1, 'deactivate_missing_enabled' => true]);

    $answer = (new CatalogImportHandler())->modeDeactivate('2026-09-07 09:29:28');

    expect($answer)->toBe(Import::answerSuccess);

    $log = ExchangeLog::where('type', ExchangeLog::TYPE_DEACTIVATE)->first();
    expect($log)->not->toBeNull();
    expect($log->shop_id)->toBe(1);
    expect($log->status)->toBe(ExchangeLog::STATUS_STARTED);

    Bus::assertDispatched(DeactivateMissingProductsJob::class, function (DeactivateMissingProductsJob $job) use ($log) {
        return $job->logId === $log->id && $job->shopId === 1 && $job->shopCode === 'shop-1';
    });
});

test('modeComplete отвечает success', function () {
    expect((new CatalogImportHandler())->modeComplete())->toBe(Import::answerSuccess);
});

test('сквозной сценарий: чанки записаны, deactivate удаляет пропавший товар (sync-очередь)', function () {
    config(['exchange1c.deactivate_missing_max_share' => 1.0]);
    makeIntegrationConfig(['shop_id' => 1, 'deactivate_missing_enabled' => true]);
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    $missing = makeProduct(['external_id' => 'p-2', 'shop_id' => 1]);

    $catalogLog = ExchangeLog::start(ExchangeLog::TYPE_CATALOG_IMPORT);
    $catalogLog->update(['shop_id' => 1, 'status' => ExchangeLog::STATUS_SUCCESS]);
    CatalogImportChunk::create([
        'shop_id' => 1,
        'exchange_log_id' => $catalogLog->id,
        'external_ids' => ['p-1'],
        'products_count' => 1,
    ]);

    (new CatalogImportHandler())->modeDeactivate('2026-09-07 09:29:28');

    expect($missing->fresh()->trashed())->toBeTrue();
    expect(Product::where('shop_id', 1)->count())->toBe(1);

    $log = ExchangeLog::where('type', ExchangeLog::TYPE_DEACTIVATE)->first();
    expect($log->status)->toBe(ExchangeLog::STATUS_SUCCESS);
    expect($log->records_count)->toBe(1);
});

test('job в статусе ожидания не трогает лог и товары', function () {
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    $pending = ExchangeLog::start(ExchangeLog::TYPE_CATALOG_IMPORT);
    $pending->update(['shop_id' => 1]);
    $log = ExchangeLog::start(ExchangeLog::TYPE_DEACTIVATE);
    $log->update(['shop_id' => 1]);

    app()->call([new DeactivateMissingProductsJob($log->id, 1, 'shop-1', '2026-09-07 09:29:28'), 'handle']);

    expect($log->fresh()->status)->toBe(ExchangeLog::STATUS_STARTED);
    expect(Product::where('shop_id', 1)->count())->toBe(1);
});

test('failed() у job помечает лог deactivate ошибкой', function () {
    $log = ExchangeLog::start(ExchangeLog::TYPE_DEACTIVATE);
    $log->update(['shop_id' => 1]);

    (new DeactivateMissingProductsJob($log->id, 1, 'shop-1', null))->failed(new RuntimeException('boom'));

    $log->refresh();
    expect($log->status)->toBe(ExchangeLog::STATUS_ERROR);
    expect($log->message)->toContain('boom');
});

test('job переживает сериализацию очередью с сохранением параметров', function () {
    $job = new DeactivateMissingProductsJob(5, 1, 'shop-1', '2026-09-07 09:29:28');

    $copy = unserialize(serialize($job));

    expect($copy->logId)->toBe(5);
    expect($copy->shopId)->toBe(1);
    expect($copy->shopCode)->toBe('shop-1');
    expect($copy->startTime)->toBe('2026-09-07 09:29:28');
});
