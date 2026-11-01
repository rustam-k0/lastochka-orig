<?php

declare(strict_types=1);

use App\Services\Exchange1C\OffersImporter;
use App\Services\Exchange1C\RestsImporter;
use Illuminate\Support\Facades\DB;

/**
 * Race-condition между ImportCatalogJob и ImportOffers/RestsJob.
 *
 * Lastochka dev 2026-01-15: ImportOffersJob завершился в 11:41:24 со skipped=1000,
 * ImportCatalogJob завершился в 11:41:31 (на 7 сек позже) — товары были созданы,
 * но 1000 цен/остатков молча потеряны. Supervisor numprocs=2 → Worker_00
 * берёт catalog, Worker_01 параллельно берёт offers, offers быстрее → product
 * не найден → silent skipped++.
 *
 * Фикс: после успешного commit, если skippedCount > 0 — throw RuntimeException.
 * Laravel queue retry'ит Job через backoff=60s (до tries=3 раз). К моменту retry
 * catalog должен пройти → product найден → success. Уже committed данные
 * сохранены (Price/Stock::updateOrCreate идемпотентны), retry лишь добивает
 * пропущенные.
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

function makeOffersXml(int $offersCount = 1): string
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

    $tmp = tempnam(sys_get_temp_dir(), 'offers_').'.xml';
    file_put_contents($tmp, $xml);

    return $tmp;
}

function makeRestsXml(int $offersCount = 1): string
{
    return makeOffersXml($offersCount); // структура верхнего уровня та же
}

/**
 * Subclass который имитирует skipped offer (product не найден),
 * не трогая БД и без обращения к Product модели.
 */
function offersImporterSkippingAll(): OffersImporter
{
    return new class extends OffersImporter
    {
        protected function importOffer(\SimpleXMLElement $offer): void
        {
            $this->skippedCount++;
        }
    };
}

function offersImporterAllOk(): OffersImporter
{
    return new class extends OffersImporter
    {
        protected function importOffer(\SimpleXMLElement $offer): void
        {
            $this->pricesCount++;
        }
    };
}

function restsImporterSkippingAll(): RestsImporter
{
    return new class extends RestsImporter
    {
        protected function importOfferRests(\SimpleXMLElement $offer): void
        {
            $this->skippedCount++;
        }
    };
}

function restsImporterAllOk(): RestsImporter
{
    return new class extends RestsImporter
    {
        protected function importOfferRests(\SimpleXMLElement $offer): void
        {
            $this->stocksCount++;
        }
    };
}

test('OffersImporter throws RuntimeException when at least one offer skipped', function () {
    $importer = offersImporterSkippingAll();
    $xmlPath = makeOffersXml(3);

    expect(fn () => $importer->import($xmlPath))
        ->toThrow(\RuntimeException::class, 'OffersImporter: 3 товаров не найдены');

    @unlink($xmlPath);
});

test('OffersImporter completes normally when no offers skipped', function () {
    $importer = offersImporterAllOk();
    $xmlPath = makeOffersXml(2);

    $result = $importer->import($xmlPath);

    expect($result)
        ->toHaveKey('prices', 2)
        ->toHaveKey('skipped', 0);

    @unlink($xmlPath);
});

test('RestsImporter returns skipped count without throwing (retry decided by Job)', function () {
    // Раньше RestsImporter сам бросал RuntimeException при skipped>0. Теперь решение
    // о retry принадлежит ImportRestsJob (release без uncaught-исключения → тихо для
    // Sentry/GlitchTip, а на последней попытке — graceful завершение с пропусками).
    // Importer лишь честно возвращает skipped, не роняя Job необработанным throw.
    $importer = restsImporterSkippingAll();
    $xmlPath = makeRestsXml(5);

    $result = $importer->import($xmlPath);

    expect($result)
        ->toHaveKey('stocks', 0)
        ->toHaveKey('skipped', 5);

    @unlink($xmlPath);
});

test('RestsImporter completes normally when no offers skipped', function () {
    $importer = restsImporterAllOk();
    $xmlPath = makeRestsXml(2);

    $result = $importer->import($xmlPath);

    expect($result)
        ->toHaveKey('stocks', 2)
        ->toHaveKey('skipped', 0);

    @unlink($xmlPath);
});

test('OffersImporter exception message names race-condition source for ops debugging', function () {
    $importer = offersImporterSkippingAll();
    $xmlPath = makeOffersXml(1);

    try {
        $importer->import($xmlPath);
        expect(true)->toBeFalse('expected RuntimeException');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toContain('race с ImportCatalogJob');
    }

    @unlink($xmlPath);
});
