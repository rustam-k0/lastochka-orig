<?php

declare(strict_types=1);

use App\Jobs\ImportRestsJob;
use App\Models\ExchangeLog;
use App\Services\Exchange1C\RestsImporter;

/**
 * ImportRestsJob: политика повтора при race-condition с ImportCatalogJob.
 *
 * GlitchTip 1C_PARSER-5: раньше RestsImporter при skipped>0 бросал RuntimeException,
 * которое как uncaught-исключение улетало в Sentry/GlitchTip на КАЖДОЙ штатной гонке,
 * а для товаров вне whitelist (которые никогда не появятся в каталоге) обмен падал
 * в failed навсегда, крутя 3 бесполезных retry.
 *
 * Теперь решение о повторе принадлежит Job:
 *  - пока остались попытки → $this->release() (штатный повтор, без uncaught-исключения
 *    → Sentry молчит, данные восстанавливаются на следующем заходе, когда catalog готов);
 *  - на последней попытке → graceful завершение обмена с пропусками (не failed): 99/100
 *    остатков импортированы идемпотентно, пропущенные догонит следующий обмен от 1С.
 *
 * Настоящие ошибки (битый XML) importer по-прежнему бросает → catch → rethrow → failed → Sentry.
 */

/**
 * Стаб-импортёр: возвращает заданный результат import() без обращения к БД/файлу,
 * изолируя проверяемую политику Job от логики парсинга.
 */
function stubRestsImporter(int $stocks, int $skipped): RestsImporter
{
    return new class($stocks, $skipped) extends RestsImporter
    {
        public function __construct(private int $stubStocks, private int $stubSkipped)
        {
            parent::__construct(null);
        }

        public function import(string $filePath, ?ExchangeLog $log = null): array
        {
            return ['stocks' => $this->stubStocks, 'skipped' => $this->stubSkipped];
        }
    };
}

/**
 * ImportRestsJob c подменённым импортёром, фиктивным номером попытки и перехватом
 * release()/cleanupFile() — чтобы наблюдать решение Job, не трогая очередь и ФС.
 */
function makeRestsJob(RestsImporter $importer, int $attempts): ImportRestsJob
{
    return new class($importer, $attempts) extends ImportRestsJob
    {
        /** @var array<int> задержки, с которыми вызвали release() */
        public array $releasedWith = [];
        public bool $cleaned = false;

        public function __construct(private RestsImporter $stubImporter, private int $fakeAttempts)
        {
            parent::__construct('/nonexistent/rests___1.xml', null, null, null);
        }

        protected function makeImporter(): RestsImporter
        {
            return $this->stubImporter;
        }

        public function attempts(): int
        {
            return $this->fakeAttempts;
        }

        public function release($delay = 0): void
        {
            $this->releasedWith[] = (int) $delay;
        }

        protected function cleanupFile(): void
        {
            $this->cleaned = true;
        }
    };
}

test('ImportRestsJob releases for retry (no fail) when products skipped and attempts remain', function () {
    // attempt 1 из tries=3 → есть куда ретраить: release с backoff, файл сохранён.
    $job = makeRestsJob(stubRestsImporter(stocks: 10, skipped: 5), attempts: 1);

    $job->handle();

    expect($job->releasedWith)->toBe([60]) // release($this->backoff) === 60
        ->and($job->cleaned)->toBeFalse();  // файл нужен для повтора — не удалён
});

test('ImportRestsJob completes gracefully (no fail, no release) when still skipped on final attempt', function () {
    // attempt 3 == tries=3 → повторять больше нельзя: не бросаем, не release,
    // завершаем обмен с пропусками (пропущенные догонит следующий обмен от 1С).
    $job = makeRestsJob(stubRestsImporter(stocks: 10, skipped: 5), attempts: 3);

    $job->handle(); // не должно бросить

    expect($job->releasedWith)->toBe([])   // без повтора
        ->and($job->cleaned)->toBeTrue();  // обмен завершён, файл очищен
});

test('ImportRestsJob completes normally when nothing skipped', function () {
    $job = makeRestsJob(stubRestsImporter(stocks: 7, skipped: 0), attempts: 1);

    $job->handle();

    expect($job->releasedWith)->toBe([])
        ->and($job->cleaned)->toBeTrue();
});
