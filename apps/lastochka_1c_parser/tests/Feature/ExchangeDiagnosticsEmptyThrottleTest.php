<?php

declare(strict_types=1);

use App\Models\ExchangeLog;
use App\Services\ExchangeDiagnosticsPublisher;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * Троттлинг «пустой» диагностики обмена 1С.
 *
 * 1С опрашивает /1c-exchange раз в 1-2 секунды. Каждый пустой опрос публиковал
 * событие `1c_exchange_status` весом ~55 КБ (снапшот проблемных товаров) —
 * 118 тыс. событий и ~3 ГБ в сутки в event_outbox плюс ~20 ГБ бинлогов,
 * что 2026-08-31 забило диск прода и уронило все бэкенды (GlitchTip issue 802).
 *
 * Пустые обмены теперь троттлятся, содержательные публикуются всегда —
 * иначе admin_back пометит обмен stale (STALE_THRESHOLDS = 2 часа).
 */
beforeEach(function () {
    Cache::flush();
});

function makeLog(string $type, string $status = ExchangeLog::STATUS_SUCCESS, int $records = 0): ExchangeLog
{
    return ExchangeLog::create([
        'type' => $type,
        'status' => $status,
        'records_count' => $records,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
}

test('пустой обмен публикуется один раз, повторы в окне троттлинга пропускаются', function () {
    $result = ['categories' => 0, 'products' => 0, 'images' => 0, 'skipped_whitelist' => 0];

    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT), $result);
    expect(EventOutbox::count())->toBe(1);

    // 1С продолжает долбить эндпоинт — новых событий быть не должно
    foreach (range(1, 5) as $ignored) {
        ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT), $result);
    }

    expect(EventOutbox::count())->toBe(1);
});

test('после истечения окна троттлинга пустой обмен публикуется снова', function () {
    $result = ['categories' => 0, 'products' => 0, 'images' => 0, 'skipped_whitelist' => 0];

    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT), $result);
    expect(EventOutbox::count())->toBe(1);

    $this->travel(ExchangeDiagnosticsPublisher::EMPTY_THROTTLE_SECONDS + 1)->seconds();

    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT), $result);
    expect(EventOutbox::count())->toBe(2);
});

test('окно троттлинга не даёт обмену протухнуть в админке', function () {
    // STALE_THRESHOLDS в admin_back — минимум 2 часа. Окно должно быть заметно меньше,
    // иначе пустые обмены перестанут обновлять last_exchange_at и статус станет stale.
    expect(ExchangeDiagnosticsPublisher::EMPTY_THROTTLE_SECONDS)->toBeLessThan(3600);
});

test('обмен с данными публикуется всегда, даже подряд', function () {
    $result = ['categories' => 3, 'products' => 42, 'images' => 7, 'skipped_whitelist' => 0];

    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT, records: 42), $result);
    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT, records: 42), $result);

    expect(EventOutbox::count())->toBe(2);
});

test('обмен с ошибкой публикуется всегда', function () {
    $log = makeLog(ExchangeLog::TYPE_OFFERS_IMPORT, ExchangeLog::STATUS_ERROR);
    $log->update(['message' => 'Не удалось разобрать XML']);

    ExchangeDiagnosticsPublisher::publish($log, ['prices' => 0, 'stocks' => 0, 'skipped' => 0]);
    ExchangeDiagnosticsPublisher::publish($log, ['prices' => 0, 'stocks' => 0, 'skipped' => 0]);

    expect(EventOutbox::count())->toBe(2);
});

test('нулевой обмен с предупреждениями импортёра публикуется', function () {
    $result = [
        'products' => 0,
        'categories' => 0,
        'warnings' => ['Категория не найдена: 42'],
    ];

    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT), $result);
    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT), $result);

    expect(EventOutbox::count())->toBe(2);
});

test('orders_export с ожидающими заказами не считается пустым', function () {
    // exported=0, но pending>0 — заказы висят неотправленными, это сигнал.
    $result = ['exported' => 0, 'pending' => 3];

    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_ORDERS_EXPORT), $result);
    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_ORDERS_EXPORT), $result);

    expect(EventOutbox::count())->toBe(2);
});

test('orders_export без заказов троттлится', function () {
    $result = ['exported' => 0, 'pending' => 0];

    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_ORDERS_EXPORT), $result);
    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_ORDERS_EXPORT), $result);

    expect(EventOutbox::count())->toBe(1);
});

test('троттлинг раздельный по типам обмена', function () {
    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT), ['products' => 0]);
    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_ORDERS_EXPORT), ['exported' => 0, 'pending' => 0]);

    // Разные data_type не должны глушить друг друга
    expect(EventOutbox::count())->toBe(2);
});

test('содержательный обмен сбрасывает окно и снова открывает его для пустых', function () {
    // Пустой — публикуется, окно закрыто
    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT), ['products' => 0]);
    expect(EventOutbox::count())->toBe(1);

    // Реальный импорт проходит независимо от окна
    ExchangeDiagnosticsPublisher::publish(
        makeLog(ExchangeLog::TYPE_CATALOG_IMPORT, records: 10),
        ['products' => 10]
    );
    expect(EventOutbox::count())->toBe(2);

    // И снова глушит последующие пустые
    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT), ['products' => 0]);
    expect(EventOutbox::count())->toBe(2);
});

test('сбой кеша не должен ронять диагностику: публикуем, а не молчим', function () {
    Cache::shouldReceive('has')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('put')->andThrow(new RuntimeException('cache down'));

    ExchangeDiagnosticsPublisher::publish(makeLog(ExchangeLog::TYPE_CATALOG_IMPORT), ['products' => 0]);

    expect(EventOutbox::count())->toBe(1);
});
