<?php

declare(strict_types=1);

use App\Services\Exchange1C\OffersImporter;
use Illuminate\Support\Facades\DB;

/**
 * Чанковый импорт offers: OffersImporter::importChunk обрабатывает только
 * предложения в окне [offset, offset+limit), а ImportOffersJob ставит
 * следующий чанк в очередь. Это позволяет планомерно импортировать каталог
 * любого размера без упора в timeout одного job (инцидент 2026-06-09:
 * 8638 offers в одной транзакции не уложились в timeout=300 → rollBack → 0).
 */
beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');

    // Импортёр обёрнут в DB::transaction($cb, $attempts) с retry на deadlock —
    // в тесте просто выполняем callback синхронно, без БД.
    DB::shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback())->byDefault();
});

afterEach(function () {
    Mockery::close();
});

function makeChunkOffersXml(int $offersCount): string
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

    $tmp = tempnam(sys_get_temp_dir(), 'chunk_offers_').'.xml';
    file_put_contents($tmp, $xml);

    return $tmp;
}

/**
 * Subclass, считающий обработанные Ид без обращения к БД.
 */
function offersImporterRecording(): OffersImporter
{
    return new class extends OffersImporter {
        public array $processedIds = [];

        protected function importOffer(\SimpleXMLElement $offer): void
        {
            $this->processedIds[] = (string) $offer->Ид;
            $this->pricesCount++;
        }
    };
}

function offersImporterChunkSkippingAll(): OffersImporter
{
    return new class extends OffersImporter {
        protected function importOffer(\SimpleXMLElement $offer): void
        {
            $this->skippedCount++;
        }
    };
}

test('importChunk processes only offers inside the offset/limit window', function () {
    $importer = offersImporterRecording();
    $xmlPath = makeChunkOffersXml(5);

    $result = $importer->importChunk($xmlPath, 1, 2);

    expect($importer->processedIds)->toBe(['id-2', 'id-3']);
    expect($result)
        ->toHaveKey('processed', 2)
        ->toHaveKey('total', 5)
        ->toHaveKey('prices', 2);

    @unlink($xmlPath);
});

test('importChunk handles the last partial window (limit exceeds remaining)', function () {
    $importer = offersImporterRecording();
    $xmlPath = makeChunkOffersXml(5);

    $result = $importer->importChunk($xmlPath, 4, 2);

    expect($importer->processedIds)->toBe(['id-5']);
    expect($result)
        ->toHaveKey('processed', 1)
        ->toHaveKey('total', 5);

    @unlink($xmlPath);
});

test('importChunk reports total independent of the window', function () {
    $importer = offersImporterRecording();
    $xmlPath = makeChunkOffersXml(8);

    $result = $importer->importChunk($xmlPath, 0, 3);

    expect($result['total'])->toBe(8);
    expect($result['processed'])->toBe(3);

    @unlink($xmlPath);
});

test('importChunk reports skipped count without throwing (retry decided by Job)', function () {
    // Race-guard больше НЕ бросается из importChunk: решение о повторе принадлежит
    // ImportOffersJob (release без uncaught-исключения → тихо для Sentry/GlitchTip,
    // а на последней попытке — продолжение пагинации с пропусками). См.
    // ImportOffersChunkRaceRetryTest. Importer лишь честно возвращает skipped.
    $importer = offersImporterChunkSkippingAll();
    $xmlPath = makeChunkOffersXml(5);

    $result = $importer->importChunk($xmlPath, 0, 2);

    expect($result['skipped'])->toBe(2)
        ->and($result['processed'])->toBe(2);

    @unlink($xmlPath);
});

test('importChunk returns file_not_found result when file is missing', function () {
    $importer = offersImporterRecording();

    $result = $importer->importChunk('/tmp/does-not-exist-'.uniqid().'.xml', 0, 100);

    expect($result['status'] ?? null)->toBe('file_not_found');

    // total отсутствует/0 — обрабатывать нечего
    expect($result['total'] ?? 0)->toBe(0);
});
