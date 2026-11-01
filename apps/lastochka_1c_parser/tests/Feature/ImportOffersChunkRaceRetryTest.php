<?php

declare(strict_types=1);

use App\Jobs\ImportOffersJob;
use App\Models\ExchangeLog;
use App\Services\Exchange1C\OffersImporter;
use Illuminate\Support\Facades\DB;

/**
 * ImportOffersJob / OffersImporter::importChunk: политика повтора при race с ImportCatalogJob.
 *
 * Симметрично GlitchTip 1C_PARSER-5 (RestsImporter). Async-путь offers (command=queue:work)
 * идёт через importChunk(): раньше он бросал RuntimeException при skipped>0 → uncaught →
 * Sentry/GlitchTip на каждой рядовой гонке, а товары вне whitelist роняли обмен в failed.
 *
 * Теперь решение о повторе — у Job:
 *  - остались попытки → release() (тихий повтор ЭТОГО чанка, без uncaught-исключения);
 *    следующий чанк НЕ планируется — он поедет после успешного прохождения текущего;
 *  - попытки исчерпаны → graceful: логируем пропуски и ПРОДОЛЖАЕМ пагинацию (иначе весь
 *    offers-обмен встал бы на «отравленном» чанке навсегда).
 *
 * Синхронный import() (CLI `1c:import`) НЕ трогаем — там throw виден оператору сразу,
 * не создаёт Sentry-шума и покрыт отдельными тестами (ImportRaceFixTest).
 */
beforeEach(function () {
    // importChunk оборачивает окно в DB::transaction($cb, $attempts) — в тесте
    // выполняем callback синхронно, без реальной БД.
    DB::shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback())->byDefault();
});

afterEach(function () {
    Mockery::close();
});

function offersChunkXml(int $offersCount): string
{
    $offers = '';
    for ($i = 1; $i <= $offersCount; $i++) {
        $offers .= "<Предложение><Ид>id-{$i}</Ид></Предложение>\n";
    }

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <ПакетПредложений>
        <Предложения>{$offers}</Предложения>
    </ПакетПредложений>
</КоммерческаяИнформация>
XML;

    $tmp = tempnam(sys_get_temp_dir(), 'offerschunk_').'.xml';
    file_put_contents($tmp, $xml);

    return $tmp;
}

test('OffersImporter importChunk returns skipped count without throwing (retry decided by Job)', function () {
    $importer = new class extends OffersImporter
    {
        protected function importOffer(\SimpleXMLElement $offer): void
        {
            $this->skippedCount++;
        }
    };
    $xmlPath = offersChunkXml(3);

    $result = $importer->importChunk($xmlPath, 0, 500);

    expect($result)
        ->toHaveKey('skipped', 3)
        ->toHaveKey('total', 3);

    @unlink($xmlPath);
});

/**
 * Стаб-импортёр: importChunk() отдаёт заданный result без БД/файла, изолируя политику Job.
 */
function stubOffersImporter(array $result): OffersImporter
{
    return new class($result) extends OffersImporter
    {
        public function __construct(private array $stubResult)
        {
            parent::__construct(null);
        }

        public function importChunk(string $filePath, int $offset, int $limit, ?ExchangeLog $log = null): array
        {
            return $this->stubResult;
        }
    };
}

/**
 * ImportOffersJob с подменённым импортёром, фиктивной попыткой и перехватом release()/cleanupFile().
 */
function makeOffersJob(OffersImporter $importer, int $attempts, int $offset = 0): ImportOffersJob
{
    // Сигнатуру конструктора НЕ меняем: handle() при пагинации делает self::dispatch() →
    // new static(filePath, logId, shopId, shopCode, next) — кастомный конструктор сломал бы это.
    // Stub передаём через публичные свойства после создания.
    $job = new class('/nonexistent/offers.xml', null, null, null, $offset) extends ImportOffersJob
    {
        /** @var array<int> */
        public array $releasedWith = [];
        /** @var array<int> offset'ы запланированных следующих чанков */
        public array $dispatchedNext = [];
        public bool $cleaned = false;
        public ?OffersImporter $stubImporter = null;
        public int $fakeAttempts = 1;

        protected function makeImporter(): OffersImporter
        {
            return $this->stubImporter;
        }

        protected function dispatchNextChunk(int $next): void
        {
            $this->dispatchedNext[] = $next;
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

    $job->stubImporter = $importer;
    $job->fakeAttempts = $attempts;

    return $job;
}

test('ImportOffersJob releases current chunk (no fail, no next chunk) when skipped and attempts remain', function () {
    // одно окно (total=3), skipped=5, attempt 1 из 3 → тихий повтор ЭТОГО чанка.
    $job = makeOffersJob(
        stubOffersImporter(['prices' => 10, 'stocks' => 0, 'skipped' => 5, 'total' => 3, 'processed' => 3]),
        attempts: 1,
    );

    $job->handle();

    expect($job->releasedWith)->toBe([60])       // release($backoff)
        ->and($job->cleaned)->toBeFalse()         // файл сохранён для повтора
        ->and($job->dispatchedNext)->toBe([]);    // следующий чанк НЕ запланирован
});

test('ImportOffersJob continues pagination (no fail, no release) when still skipped on final attempt', function () {
    // total=600, offset=0, chunkSize=500 → есть следующий чанк (next=500<600).
    // skipped=5 на последней попытке (3/3) → не роняем обмен, продолжаем пагинацию.
    $job = makeOffersJob(
        stubOffersImporter(['prices' => 490, 'stocks' => 0, 'skipped' => 5, 'total' => 600, 'processed' => 500]),
        attempts: 3,
    );

    $job->handle();

    expect($job->releasedWith)->toBe([])              // без повтора
        ->and($job->dispatchedNext)->toBe([500]);     // следующий чанк запланирован несмотря на пропуски
});

test('ImportOffersJob finalizes normally when nothing skipped on last chunk', function () {
    $job = makeOffersJob(
        stubOffersImporter(['prices' => 3, 'stocks' => 0, 'skipped' => 0, 'total' => 3, 'processed' => 3]),
        attempts: 1,
    );

    $job->handle();

    expect($job->releasedWith)->toBe([])
        ->and($job->cleaned)->toBeTrue()          // последний чанк → файл очищен
        ->and($job->dispatchedNext)->toBe([]);
});
