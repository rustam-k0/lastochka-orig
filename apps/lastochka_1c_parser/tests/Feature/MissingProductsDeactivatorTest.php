<?php

declare(strict_types=1);

use App\Models\CatalogImportChunk;
use App\Models\ExchangeLog;
use App\Models\Product;
use App\Services\Exchange1C\MissingProductsDeactivator;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Шаг deactivate от 1С: объединить состав файлов полной выгрузки (чанки),
 * найти товары магазина, которых в выгрузке нет, и мягко удалить их.
 *
 * Окно выгрузки ограничено id логов: (предыдущий лог deactivate, текущий лог
 * deactivate). Auto-increment id даёт порядок без зависимости от часов 1С.
 */
beforeEach(function () {
    config(['exchange1c.deactivate_missing_max_share' => 0.5]);
});

function catalogLog(int $shopId, string $status = ExchangeLog::STATUS_SUCCESS): ExchangeLog
{
    $log = ExchangeLog::start(ExchangeLog::TYPE_CATALOG_IMPORT);
    $log->update(['shop_id' => $shopId, 'status' => $status]);

    return $log;
}

function deactivateLog(int $shopId): ExchangeLog
{
    $log = ExchangeLog::start(ExchangeLog::TYPE_DEACTIVATE);
    $log->update(['shop_id' => $shopId]);

    return $log;
}

/**
 * Outbox-observers регистрируются один раз на процесс со значением
 * rabbitmq-events.enabled на момент boot'а; в тестах его включаем и
 * перерегистрируем (как в CatalogImportDelProdTest).
 */
function reregisterOutboxObserversForDeactivate(): void
{
    config()->set('rabbitmq-events.enabled', true);

    (new ReflectionProperty(
        \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
        'observersRegistered'
    ))->setValue(null, false);

    (new \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider(app()))->boot();
}

function chunkFor(ExchangeLog $log, array $externalIds): CatalogImportChunk
{
    return CatalogImportChunk::create([
        'shop_id' => $log->shop_id,
        'exchange_log_id' => $log->id,
        'external_ids' => $externalIds,
        'products_count' => count($externalIds),
    ]);
}

test('удаляет товары магазина, которых нет в составе выгрузки, и потребляет чанки', function () {
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    makeProduct(['external_id' => 'p-2', 'shop_id' => 1]);
    $missing = makeProduct(['external_id' => 'p-3', 'shop_id' => 1]);
    // В sqlite external_id уникален глобально (составной UNIQUE с shop_id — MySQL-only миграция).
    $otherShop = makeProduct(['external_id' => 'p-9', 'shop_id' => 2]);

    chunkFor(catalogLog(1), ['p-1']);
    chunkFor(catalogLog(1), ['p-2']);
    $log = deactivateLog(1);

    $result = (new MissingProductsDeactivator())->run($log, 1);

    expect($result->status)->toBe(MissingProductsDeactivator::STATUS_DONE);
    expect($result->deleted)->toBe(1);
    expect(Product::where('shop_id', 1)->pluck('external_id')->all())->toBe(['p-1', 'p-2']);
    expect($missing->fresh()->trashed())->toBeTrue();
    expect($otherShop->fresh()->trashed())->toBeFalse();
    expect(CatalogImportChunk::count())->toBe(0);

    $log->refresh();
    expect($log->status)->toBe(ExchangeLog::STATUS_SUCCESS);
    expect($log->records_count)->toBe(1);
});

test('ждёт, пока файл каталога из окна выгрузки ещё в обработке', function () {
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    chunkFor(catalogLog(1), []);
    catalogLog(1, ExchangeLog::STATUS_STARTED);
    $log = deactivateLog(1);

    $result = (new MissingProductsDeactivator())->run($log, 1);

    expect($result->status)->toBe(MissingProductsDeactivator::STATUS_WAITING);
    expect(Product::where('shop_id', 1)->count())->toBe(1);
    expect(CatalogImportChunk::count())->toBe(1);
    expect($log->fresh()->status)->toBe(ExchangeLog::STATUS_STARTED);
});

test('отменяет удаление, если файл каталога из окна выгрузки упал', function () {
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    chunkFor(catalogLog(1), []);
    catalogLog(1, ExchangeLog::STATUS_ERROR);
    $log = deactivateLog(1);

    $result = (new MissingProductsDeactivator())->run($log, 1);

    expect($result->status)->toBe(MissingProductsDeactivator::STATUS_ABORTED);
    expect(Product::where('shop_id', 1)->count())->toBe(1);
    expect($log->fresh()->status)->toBe(ExchangeLog::STATUS_ERROR);
    // Чанки потреблены: следующая выгрузка начнёт с чистого окна.
    expect(CatalogImportChunk::count())->toBe(0);
});

test('отменяет удаление, если у файла каталога из окна нет записи о составе', function () {
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    chunkFor(catalogLog(1), []);
    catalogLog(1); // success, но без чанка: фичу включили посреди выгрузки
    $log = deactivateLog(1);

    $result = (new MissingProductsDeactivator())->run($log, 1);

    expect($result->status)->toBe(MissingProductsDeactivator::STATUS_ABORTED);
    expect(Product::where('shop_id', 1)->count())->toBe(1);
    expect($log->fresh()->status)->toBe(ExchangeLog::STATUS_ERROR);
});

test('отменяет удаление, если нет ни одного чанка', function () {
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    $log = deactivateLog(1);

    $result = (new MissingProductsDeactivator())->run($log, 1);

    expect($result->status)->toBe(MissingProductsDeactivator::STATUS_ABORTED);
    expect(Product::where('shop_id', 1)->count())->toBe(1);
});

test('отменяет удаление при превышении порога доли пропавших товаров', function () {
    config(['exchange1c.deactivate_missing_max_share' => 0.2]);
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    makeProduct(['external_id' => 'p-2', 'shop_id' => 1]);
    makeProduct(['external_id' => 'p-3', 'shop_id' => 1]);
    makeProduct(['external_id' => 'p-4', 'shop_id' => 1]);
    // Пропали 2 из 4 = 50% > 20%.
    chunkFor(catalogLog(1), ['p-1', 'p-2']);
    $log = deactivateLog(1);

    $result = (new MissingProductsDeactivator())->run($log, 1);

    expect($result->status)->toBe(MissingProductsDeactivator::STATUS_ABORTED);
    expect($result->missing)->toBe(2);
    expect(Product::where('shop_id', 1)->count())->toBe(4);
    expect($log->fresh()->status)->toBe(ExchangeLog::STATUS_ERROR);
    expect(CatalogImportChunk::count())->toBe(0);
});

test('доля ровно на пороге ещё допустима', function () {
    config(['exchange1c.deactivate_missing_max_share' => 0.5]);
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    makeProduct(['external_id' => 'p-2', 'shop_id' => 1]);
    chunkFor(catalogLog(1), ['p-1']);
    $log = deactivateLog(1);

    $result = (new MissingProductsDeactivator())->run($log, 1);

    expect($result->status)->toBe(MissingProductsDeactivator::STATUS_DONE);
    expect($result->deleted)->toBe(1);
});

test('логи и чанки следующей выгрузки (после текущего лога deactivate) не трогает', function () {
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    makeProduct(['external_id' => 'p-2', 'shop_id' => 1]);
    chunkFor(catalogLog(1), ['p-1', 'p-2']);
    $log = deactivateLog(1);
    // Следующая выгрузка уже пошла: файл в обработке и один готовый чанк.
    catalogLog(1, ExchangeLog::STATUS_STARTED);
    $nextChunk = chunkFor(catalogLog(1), ['p-1']);

    $result = (new MissingProductsDeactivator())->run($log, 1);

    expect($result->status)->toBe(MissingProductsDeactivator::STATUS_DONE);
    expect($result->deleted)->toBe(0);
    expect(CatalogImportChunk::find($nextChunk->id))->not->toBeNull();
});

test('предыдущий лог deactivate ограничивает окно: старые ошибки не мешают', function () {
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    makeProduct(['external_id' => 'p-2', 'shop_id' => 1]);
    catalogLog(1, ExchangeLog::STATUS_ERROR);
    $previous = deactivateLog(1);
    $previous->update(['status' => ExchangeLog::STATUS_ERROR]);
    chunkFor(catalogLog(1), ['p-1']);
    $log = deactivateLog(1);

    $result = (new MissingProductsDeactivator())->run($log, 1);

    expect($result->status)->toBe(MissingProductsDeactivator::STATUS_DONE);
    expect($result->deleted)->toBe(1);
});

test('чанки прерванной прошлой выгрузки объединяются с текущей: только надмножество', function () {
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    makeProduct(['external_id' => 'p-2', 'shop_id' => 1]);
    makeProduct(['external_id' => 'p-3', 'shop_id' => 1]);
    // Прошлая выгрузка оборвалась после первого файла, deactivate не пришёл.
    chunkFor(catalogLog(1), ['p-2']);
    // Текущая полная выгрузка: p-1 и p-3, p-2 в ней уже нет.
    chunkFor(catalogLog(1), ['p-1', 'p-3']);
    $log = deactivateLog(1);

    $result = (new MissingProductsDeactivator())->run($log, 1);

    // p-2 остаётся: он был в объединённом составе. Удалится следующей выгрузкой.
    expect($result->status)->toBe(MissingProductsDeactivator::STATUS_DONE);
    expect($result->deleted)->toBe(0);
    expect(Product::where('shop_id', 1)->count())->toBe(3);
    expect(CatalogImportChunk::count())->toBe(0);
});

test('чанки чужого магазина не участвуют и не потребляются', function () {
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    $foreign = chunkFor(catalogLog(2), ['p-1']);
    chunkFor(catalogLog(1), ['p-1']);
    $log = deactivateLog(1);

    (new MissingProductsDeactivator())->run($log, 1);

    expect(CatalogImportChunk::find($foreign->id))->not->toBeNull();
});

test('уже удалённые товары не считаются пропавшими', function () {
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    makeProduct(['external_id' => 'p-2', 'shop_id' => 1])->delete();
    chunkFor(catalogLog(1), ['p-1']);
    $log = deactivateLog(1);

    $result = (new MissingProductsDeactivator())->run($log, 1);

    expect($result->status)->toBe(MissingProductsDeactivator::STATUS_DONE);
    expect($result->deleted)->toBe(0);
    expect($result->missing)->toBe(0);
});

test('удаление кладёт в outbox событие deleted по каждому товару', function () {
    reregisterOutboxObserversForDeactivate();
    // Пропали все товары магазина: порог поднимаем, чтобы дойти до удаления.
    config(['exchange1c.deactivate_missing_max_share' => 1.0]);
    makeProduct(['external_id' => 'p-1', 'shop_id' => 1]);
    makeProduct(['external_id' => 'p-2', 'shop_id' => 1]);
    chunkFor(catalogLog(1), []);
    $log = deactivateLog(1);
    EventOutbox::query()->delete();

    (new MissingProductsDeactivator())->run($log, 1);

    $events = EventOutbox::where('entity_type', 'products')->get();
    expect($events)->toHaveCount(2);
    expect($events->pluck('event_type')->unique()->all())->toBe(['deleted']);
});
