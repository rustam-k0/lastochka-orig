<?php

declare(strict_types=1);

use App\Services\Exchange1C\CatalogImporter;
use App\Services\Exchange1C\OffersImporter;
use App\Services\Exchange1C\ProductWhitelist;
use App\Services\Exchange1C\RestsImporter;
use Illuminate\Support\Facades\DB;

/**
 * Фикс deadlock импорта 1С (GlitchTip 1C_PARSER-2, SQLSTATE 40001 / 1213).
 *
 * Инцидент: два параллельных queue-воркера (numprocs=2) обновляли пересекающиеся
 * строки `products` в разном порядке — CatalogImporter держал одну гигантскую
 * транзакцию на весь каталог (в пойманном дедлоке 602 undo entries) с
 * `updateOrCreate(...synced_at)`, а Offers/RestsImporter параллельно делали
 * `$product->touch()`. InnoDB откатывал жертву.
 *
 * Здесь проверяем слои 1 и 3 фикса:
 *  - импорт обёрнут в DB::transaction с retry на deadlock (attempts=5);
 *  - каталог дробится на батчи по batch_size (окно X-locks ограничено батчем).
 *
 * DB::transaction замокан — callback выполняется синхронно, а мы фиксируем
 * число вызовов и переданное число попыток.
 */
beforeEach(function () {
    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');
});

afterEach(function () {
    Mockery::close();
});

function deadlockRetryOffersXml(int $count): string
{
    $offers = '';
    for ($i = 1; $i <= $count; $i++) {
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

    $tmp = tempnam(sys_get_temp_dir(), 'deadlock_offers_').'.xml';
    file_put_contents($tmp, $xml);

    return $tmp;
}

function deadlockRetryCatalogXml(int $count): string
{
    $items = '';
    for ($i = 1; $i <= $count; $i++) {
        $items .= "<Товар><Ид>guid-{$i}</Ид><Наименование>Товар {$i}</Наименование></Товар>\n";
    }

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Каталог>
        <Товары>{$items}</Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;

    $tmp = tempnam(sys_get_temp_dir(), 'deadlock_catalog_').'.xml';
    file_put_contents($tmp, $xml);

    return $tmp;
}

/**
 * Хелпер: мок DB::transaction, который выполняет callback синхронно и пишет
 * переданное число попыток в $calls (по ссылке).
 */
function mockTransactionCapturing(array &$calls): void
{
    DB::shouldReceive('transaction')->andReturnUsing(function ($callback, $attempts = 1) use (&$calls) {
        $calls[] = $attempts;

        return $callback();
    });
}

test('OffersImporter::importChunk wraps the window in DB::transaction with deadlock retry', function () {
    $calls = [];
    mockTransactionCapturing($calls);

    $importer = new class extends OffersImporter {
        protected function importOffer(\SimpleXMLElement $offer): void
        {
            $this->pricesCount++;
        }
    };
    $xmlPath = deadlockRetryOffersXml(3);

    $importer->importChunk($xmlPath, 0, 10);

    // Ровно одна транзакция окна, с 5 попытками retry на 1213.
    expect($calls)->toBe([5]);

    @unlink($xmlPath);
});

test('RestsImporter::import wraps the import in DB::transaction with deadlock retry', function () {
    $calls = [];
    mockTransactionCapturing($calls);

    $importer = new class extends RestsImporter {
        protected function importOfferRests(\SimpleXMLElement $offer): void
        {
            $this->stocksCount++;
        }
    };
    $xmlPath = deadlockRetryOffersXml(2);

    $importer->import($xmlPath);

    expect($calls)->toBe([5]);

    @unlink($xmlPath);
});

test('CatalogImporter splits products into batched transactions of batch_size, each with retry', function () {
    config()->set('exchange1c.batch_size', 2);

    $calls = [];
    mockTransactionCapturing($calls);

    $importer = new class(new ProductWhitelist(null), null) extends CatalogImporter {
        public int $imported = 0;

        protected function importProduct(\SimpleXMLElement $item): void
        {
            $this->imported++;
            $this->productsCount++;
        }
    };
    // 5 товаров, без Классификатора → 3 батча (2 + 2 + 1), каждый в своей транзакции.
    $xmlPath = deadlockRetryCatalogXml(5);

    $importer->import($xmlPath);

    expect($calls)->toBe([5, 5, 5]);
    // Все товары импортированы несмотря на дробление на батчи.
    expect($importer->imported)->toBe(5);

    @unlink($xmlPath);
});

test('CatalogImporter commits categories in their own transaction before product batches', function () {
    config()->set('exchange1c.batch_size', 500);

    $calls = [];
    mockTransactionCapturing($calls);

    $importer = new class(new ProductWhitelist(null), null) extends CatalogImporter {
        protected function importProduct(\SimpleXMLElement $item): void
        {
            $this->productsCount++;
        }

        protected function importClassifier(\SimpleXMLElement $classifier): void
        {
            $this->categoriesCount++;
        }
    };

    // Каталог с классификатором (1 категория) и 2 товарами.
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация>
    <Классификатор>
        <Группы>
            <Группа><Ид>cat-1</Ид><Наименование>Категория</Наименование></Группа>
        </Группы>
    </Классификатор>
    <Каталог>
        <Товары>
            <Товар><Ид>guid-1</Ид><Наименование>Товар 1</Наименование></Товар>
            <Товар><Ид>guid-2</Ид><Наименование>Товар 2</Наименование></Товар>
        </Товары>
    </Каталог>
</КоммерческаяИнформация>
XML;
    $xmlPath = tempnam(sys_get_temp_dir(), 'deadlock_full_').'.xml';
    file_put_contents($xmlPath, $xml);

    $importer->import($xmlPath);

    // 1 транзакция категорий + 1 батч товаров (batch_size=500 вмещает оба) = 2 транзакции.
    expect($calls)->toBe([5, 5]);

    @unlink($xmlPath);
});
