<?php

declare(strict_types=1);

use App\Jobs\ImportOffersJob;
use App\Models\ExchangeLog;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * ImportOffersJob — самочейнящийся чанковый импорт offers.
 *
 * Вместо одной транзакции на весь offers.xml (8638 предложений не уложились
 * в timeout=300 → rollBack → 0 импортировано, инцидент 2026-06-09) job
 * обрабатывает окно batch_size предложений и ставит следующий чанк в очередь.
 * Так импорт идёт планомерно и масштабируется на любой размер каталога.
 */
beforeEach(function () {
    config()->set('rabbitmq-events.enabled', false); // не публикуем в RabbitMQ в тесте
    config()->set('exchange1c.batch_size', 2);
});

function makeJobOffersXml(int $offersCount): string
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

    // Путь содержит "1cExchange", чтобы сработал cleanupFile() на последнем чанке.
    $dir = sys_get_temp_dir().'/1cExchange';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $tmp = $dir.'/offers_'.uniqid().'.xml';
    file_put_contents($tmp, $xml);

    return $tmp;
}

function seedOfferProducts(int $count): void
{
    Product::factory()
        ->withoutCategory()
        ->count($count)
        ->sequence(fn ($seq) => ['external_id' => 'id-'.($seq->index + 1), 'shop_id' => 1])
        ->create();
}

test('ImportOffersJob dispatches the next chunk when more offers remain', function () {
    Queue::fake();
    seedOfferProducts(5);
    $xmlPath = makeJobOffersXml(5);
    $log = ExchangeLog::start(ExchangeLog::TYPE_OFFERS_IMPORT);

    (new ImportOffersJob($xmlPath, $log->id, null, 'aladdin', 0))->handle();

    Queue::assertPushed(ImportOffersJob::class, fn ($job) => $job->offset === 2);
    expect(file_exists($xmlPath))->toBeTrue('файл не должен удаляться до последнего чанка');

    @unlink($xmlPath);
});

test('ImportOffersJob on the last chunk does not dispatch, cleans file, marks log success', function () {
    Queue::fake();
    seedOfferProducts(5);
    $xmlPath = makeJobOffersXml(5);
    $log = ExchangeLog::start(ExchangeLog::TYPE_OFFERS_IMPORT);

    // offset=4, batch_size=2 → обрабатывается только id-5; 4+2 >= 5 → следующего нет
    (new ImportOffersJob($xmlPath, $log->id, null, 'aladdin', 4))->handle();

    Queue::assertNotPushed(ImportOffersJob::class);
    expect(file_exists($xmlPath))->toBeFalse('последний чанк должен удалить файл');
    expect($log->fresh()->status)->toBe(ExchangeLog::STATUS_SUCCESS);
});

test('ImportOffersJob runs the whole catalog chunk-by-chunk via the real queue', function () {
    // sync queue (phpunit.xml QUEUE_CONNECTION=sync) → self-dispatch выполняется сразу,
    // поэтому один dispatch проходит весь каталог по чанкам.
    seedOfferProducts(5);
    $xmlPath = makeJobOffersXml(5);
    $log = ExchangeLog::start(ExchangeLog::TYPE_OFFERS_IMPORT);

    ImportOffersJob::dispatch($xmlPath, $log->id, null, 'aladdin', 0);

    expect($log->fresh()->status)->toBe(ExchangeLog::STATUS_SUCCESS);
    expect(file_exists($xmlPath))->toBeFalse();
});
