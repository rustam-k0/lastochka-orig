<?php

use App\Models\ExchangeLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Батчевое удаление в 1c:cleanup --logs.
 *
 * Одиночный DELETE на всём объёме держит блокировки и останавливает запись
 * обмена: на проде Ласточки в exchange_logs 862 тыс. строк при ~70 вставках
 * в минуту от 1С, под удаление уходило до 376 тыс. записей за раз.
 */
function makeExchangeLog(string $createdAt): ExchangeLog
{
    $log = ExchangeLog::create([
        'type' => 'catalog',
        'status' => ExchangeLog::STATUS_SUCCESS,
        'message' => 'batching probe',
    ]);

    // created_at не входит в fillable — проставляем отдельно.
    ExchangeLog::where('id', $log->id)->update(['created_at' => $createdAt]);

    return $log->fresh();
}

it('удаляет все подходящие логи за несколько батчей', function () {
    foreach (range(1, 7) as $i) {
        makeExchangeLog(now()->subDays(30)->toDateTimeString());
    }

    $deleteQueries = 0;
    DB::listen(function ($query) use (&$deleteQueries) {
        if (stripos(ltrim($query->sql), 'delete') === 0
            && stripos($query->sql, 'exchange_logs') !== false) {
            $deleteQueries++;
        }
    });

    $this->artisan('1c:cleanup', ['--days' => 7, '--logs' => true, '--chunk' => 2])
        ->assertExitCode(0);

    expect(ExchangeLog::count())->toBe(0);

    // Суть фикса: удаление разбито на батчи, а не выполнено одним DELETE,
    // который держит блокировки и не даёт 1С писать в таблицу.
    expect($deleteQueries)->toBeGreaterThan(1);
});

it('не трогает свежие логи', function () {
    $stale = makeExchangeLog(now()->subDays(30)->toDateTimeString());
    $fresh = makeExchangeLog(now()->subDay()->toDateTimeString());

    $this->artisan('1c:cleanup', ['--days' => 7, '--logs' => true, '--chunk' => 2])
        ->assertExitCode(0);

    expect(ExchangeLog::find($stale->id))->toBeNull();
    expect(ExchangeLog::find($fresh->id))->not->toBeNull();
});

it('chunk=0 возвращает прежнее поведение одним DELETE', function () {
    foreach (range(1, 3) as $i) {
        makeExchangeLog(now()->subDays(30)->toDateTimeString());
    }

    $deleteQueries = 0;
    DB::listen(function ($query) use (&$deleteQueries) {
        if (stripos(ltrim($query->sql), 'delete') === 0
            && stripos($query->sql, 'exchange_logs') !== false) {
            $deleteQueries++;
        }
    });

    $this->artisan('1c:cleanup', ['--days' => 7, '--logs' => true, '--chunk' => 0])
        ->assertExitCode(0);

    expect(ExchangeLog::count())->toBe(0);
    expect($deleteQueries)->toBe(1);
});

it('без --logs не трогает таблицу логов', function () {
    makeExchangeLog(now()->subDays(30)->toDateTimeString());

    $this->artisan('1c:cleanup', ['--days' => 7])
        ->assertExitCode(0);

    expect(ExchangeLog::count())->toBe(1);
});
