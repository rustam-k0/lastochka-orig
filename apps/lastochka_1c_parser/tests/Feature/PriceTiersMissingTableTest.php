<?php

declare(strict_types=1);

use App\Models\Price;
use App\Models\PriceTier;
use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use App\Services\Exchange1C\OffersImporter;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

/**
 * MID-308 (P1). Окно выкладки: git pull → composer install → migrate --force →
 * рестарт воркеров. Воркер, уже загрузивший новый код до миграции, не должен
 * падать на первом же обращении к таблице price_tiers — ни в OffersImporter,
 * ни в ProductEventTransformer при сборке любого события товара.
 *
 * Таблица удаляется прямо в тесте (Schema::drop). RefreshDatabase на sqlite
 * :memory: оборачивает тест в транзакцию, а DDL в sqlite транзакционный —
 * после теста таблица восстанавливается автоматически. Статический кэш
 * PriceTier::tableReady() — это PHP static, транзакция БД его не откатывает,
 * поэтому сбрасываем его явно в afterEach.
 */
beforeEach(function () {
    // Статический кэш готовности таблицы (PHP static, не часть DB-транзакции)
    // мог остаться выставленным в true другим тестовым файлом того же процесса —
    // сбрасываем его до каждого теста, а не только после.
    PriceTier::forgetTableReady();

    config()->set('rabbitmq-events.enabled', true);
    config()->set('rabbitmq-events.shop_code', 'aladdin');
    config()->set('rabbitmq-events.source', '1c');
    config()->set('exchange1c.retail_price_types', ['Розничная', 'ОПТ']);
    config()->set('exchange1c.cost_price_types', ['Закупочная']);

    PriceTiersMissingTableRecorder::$payloads = [];

    $mock = Mockery::mock('alias:'.CustomEventPublisher::class);
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($entityType, $model, $eventType, $payload) {
            PriceTiersMissingTableRecorder::$payloads[] = $payload;

            return 'test-event-id';
        });
});

afterEach(function () {
    PriceTier::forgetTableReady();
});

class PriceTiersMissingTableRecorder
{
    /** @var array<int, array<string, mixed>> */
    public static array $payloads = [];
}

/**
 * @param  array<int, array{0: string, 1: string}>|null  $tiers  [[Количество, ЦенаЗаЕдиницу], ...]; null = тега нет
 */
function missingTableOfferXml(string $productId, string $price, ?array $tiers): SimpleXMLElement
{
    $tiersTag = '';

    if ($tiers !== null) {
        $rows = '';
        foreach ($tiers as [$quantity, $tierPrice]) {
            $rows .= "<ЦенаОтКоличества><Количество>{$quantity}</Количество><ЦенаЗаЕдиницу>{$tierPrice}</ЦенаЗаЕдиницу></ЦенаОтКоличества>";
        }
        $tiersTag = "<ЦеныОтКоличества>{$rows}</ЦеныОтКоличества>";
    }

    $xml = <<<XML
    <Предложение>
        <Ид>{$productId}</Ид>
        <Цены>
            <Цена>
                <ИдТипаЦены>Розничная</ИдТипаЦены>
                <ЦенаЗаЕдиницу>{$price}</ЦенаЗаЕдиницу>
                {$tiersTag}
                <Валюта>RUB</Валюта>
            </Цена>
        </Цены>
    </Предложение>
    XML;

    return new SimpleXMLElement($xml);
}

function missingTableImporter(): OffersImporter
{
    return new class extends OffersImporter
    {
        public function exposeImportPrices(Product $product, SimpleXMLElement $offer): void
        {
            $this->importPrices($product, $offer);
        }
    };
}

test('без таблицы price_tiers: предложение без тега лесенки импортируется без исключений', function () {
    Schema::drop('price_tiers');

    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    $offer = missingTableOfferXml((string) $product->external_id, '279', null);

    missingTableImporter()->exposeImportPrices($product, $offer);

    expect((float) Price::where('product_id', $product->id)->value('value'))->toBe(279.0);
});

test('без таблицы price_tiers: предложение с тегом лесенки импортируется, лесенка игнорируется, warning один раз', function () {
    Schema::drop('price_tiers');

    $channel = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('1c-exchange')->andReturn($channel);

    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    $offer = missingTableOfferXml((string) $product->external_id, '279', [['2', '249'], ['5', '199']]);

    missingTableImporter()->exposeImportPrices($product, $offer);

    expect((float) Price::where('product_id', $product->id)->value('value'))->toBe(279.0);
    $channel->shouldHaveReceived('warning')->once();
});

test('без таблицы price_tiers: toEvent() и price_updated собираются без ключа price_tiers и без исключений', function () {
    $product = Product::factory()->withoutCategory()->create(['shop_id' => 3]);
    Price::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'value' => 279, 'currency' => 'RUB']);

    Schema::drop('price_tiers');

    $transformer = new ProductEventTransformer();

    $eventPayload = $transformer->toEvent($product->fresh());
    expect($eventPayload)->not->toHaveKey('price_tiers');

    $priceUpdatedPayload = $transformer->toCustomEvent($product->fresh(), 'price_updated', [
        'price' => 279.0, 'old_price' => 279.0, 'price_type' => 'Розничная', 'currency' => 'RUB',
    ]);
    expect($priceUpdatedPayload)->not->toHaveKey('price_tiers');
});
