<?php

namespace Tests\Feature\Aggregator;

use App\Aggregator\Events\ProductImportedFromEvent;
use App\Aggregator\Importers\AggregatorOfferImporter;
use App\Models\AggregatorQuarantine;
use App\Models\PlatformProduct;
use App\Models\PlatformProductBarcode;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductOffer;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тесты ядра дедупа aggregator-режима (FOODLINK-10874, docs/aggregator-mode.md §15.1).
 * Импортёр вызывается напрямую (без флага/listener'а) — изолированная проверка логики.
 */
class AggregatorOfferImporterTest extends TestCase
{
    use DatabaseTransactions;

    private AggregatorOfferImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aggregator.quarantine_hidden_status_id' => Status::CODE_NOT_PUBLISHED]);
        $this->importer = new AggregatorOfferImporter();
    }

    /** Создать магазин с уникальным кодом. */
    private function makeShop(string $code): Shop
    {
        return Shop::create(['code' => $code, 'title' => 'Shop ' . $code]);
    }

    /** Создать товар поставильщика с ценой и остатком. */
    private function makeProduct(Shop $shop, string $title, int $price = 1000, float $qty = 5, int $status = Status::CODE_ACTIVE): Product
    {
        $product = Product::withoutEvents(function () use ($shop, $title, $status) {
            return Product::create([
                'title' => $title,
                'articul' => uniqid('art_'),
                'shop_id' => $shop->id,
                'status_id' => $status,
                'unit_type_id' => Type::UNIT_PIECE,
            ]);
        });

        Price::forceCreate([
            'product_id' => $product->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => $price,
            'step' => 1,
        ]);
        Remain::create([
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'quantity' => $qty,
        ]);

        return $product->refresh();
    }

    private function import(Product $product, array $barcodes, array $extra = []): void
    {
        $this->importer->handle(new ProductImportedFromEvent(
            $product,
            array_merge(['title' => $product->title, 'barcodes' => $barcodes], $extra)
        ));
    }

    public function test_happy_path_creates_platform_product_offer_and_barcode(): void
    {
        $shop = $this->makeShop('dagbars');
        $product = $this->makeProduct($shop, 'Молоко 3.2%', 12990, 47);

        $this->import($product, ['4607034012345'], ['min_qty' => 2]);

        $this->assertDatabaseCount('platform_products', 1);
        $platform = PlatformProduct::first();
        $this->assertSame('Молоко 3.2%', $platform->canonical_title);

        $this->assertDatabaseHas('platform_product_barcodes', [
            'platform_product_id' => $platform->id,
            'ean' => '4607034012345',
            'is_primary' => 1,
        ]);

        $offer = ProductOffer::first();
        $this->assertSame($platform->id, $offer->platform_product_id);
        $this->assertSame($shop->id, $offer->shop_id);
        $this->assertSame($product->id, $offer->local_product_id);
        $this->assertSame(12990, $offer->price);
        $this->assertEquals(47.0, (float) $offer->qty);
        $this->assertEquals(2.0, (float) $offer->min_qty);
        $this->assertTrue((bool) $offer->is_active);
    }

    public function test_same_barcode_from_two_shops_yields_one_card_two_offers(): void
    {
        $shopA = $this->makeShop('dagbars');
        $shopB = $this->makeShop('tsk');
        $productA = $this->makeProduct($shopA, 'Молоко Даг Барс', 12990);
        $productB = $this->makeProduct($shopB, 'Молоко ТСК', 13990);

        $this->import($productA, ['4607034012345']);
        $this->import($productB, ['4607034012345']);

        // Одна карточка, два оффера, один штрихкод (не задвоился).
        $this->assertDatabaseCount('platform_products', 1);
        $this->assertDatabaseCount('product_offers', 2);
        $this->assertDatabaseCount('platform_product_barcodes', 1);

        $platform = PlatformProduct::first();
        $this->assertSame(2, $platform->offers()->count());
    }

    public function test_partner_b_extra_barcode_is_unioned_into_same_card(): void
    {
        $shopA = $this->makeShop('dagbars');
        $shopB = $this->makeShop('tsk');
        $productA = $this->makeProduct($shopA, 'Молоко', 12990);
        $productB = $this->makeProduct($shopB, 'Молоко', 13990);

        $this->import($productA, ['4607034012345']);
        // Партнёр B присылает ИЗВЕСТНЫЙ штрихкод + новый (мультиупаковка) — карточка та же.
        $this->import($productB, ['4607034012345', '4607034098765']);

        $this->assertDatabaseCount('platform_products', 1);
        $this->assertDatabaseCount('product_offers', 2);
        $this->assertDatabaseCount('platform_product_barcodes', 2);
    }

    public function test_barcode_change_for_same_supplier_does_not_create_new_card(): void
    {
        $shop = $this->makeShop('dagbars');
        $product = $this->makeProduct($shop, 'Молоко', 12990);

        $this->import($product, ['4607034012345']);
        // Тот же GUID/поставщик присылает НОВЫЙ штрихкод — оффер найден по вторичному ключу,
        // карточка та же, новый EAN дописан.
        $this->import($product, ['4607034099999']);

        $this->assertDatabaseCount('platform_products', 1);
        $this->assertDatabaseCount('product_offers', 1);
        $this->assertDatabaseCount('platform_product_barcodes', 2);

        $platform = PlatformProduct::first();
        $this->assertEqualsCanonicalizing(
            ['4607034012345', '4607034099999'],
            $platform->barcodes()->pluck('ean')->all()
        );
    }

    public function test_no_ean_goes_to_quarantine_no_offer_created(): void
    {
        $shop = $this->makeShop('dagbars');
        $product = $this->makeProduct($shop, 'Безымянный товар', 1000);

        $this->import($product, []);

        $this->assertDatabaseCount('product_offers', 0);
        $this->assertDatabaseCount('platform_products', 0);
        $this->assertDatabaseHas('aggregator_quarantine', [
            'shop_id' => $shop->id,
            'local_product_id' => $product->id,
            'reason' => AggregatorQuarantine::REASON_NO_EAN,
            'resolved_at' => null,
        ]);
        // Товар скрыт с витрины.
        $this->assertSame(Status::CODE_NOT_PUBLISHED, $product->fresh()->status_id);
    }

    public function test_quarantine_hide_logs_product_history_with_system_actor(): void
    {
        // LASTOCHKI-JAMI-11983 §5: карантин — единственный автоматический процесс,
        // которому осознанно разрешено менять статус, но изменение обязано попадать
        // в аудит-историю с пометкой источника (иначе неотличимо от ручной деактивации).
        $shop = $this->makeShop('dagbars');
        $product = $this->makeProduct($shop, 'Безымянный товар', 1000, 5, Status::CODE_ACTIVE);

        $this->import($product, []);

        $history = ProductHistory::where('product_id', $product->id)
            ->where('action', ProductHistory::ACTION_STATUS_CHANGED)
            ->latest('id')
            ->first();

        $this->assertNotNull($history, 'Карантин должен фиксировать скрытие товара в истории статуса');
        $this->assertSame(ProductHistory::ACTOR_SYSTEM, $history->actor_type);
        $this->assertSame((string) Status::CODE_ACTIVE, $history->old_value);
        $this->assertSame((string) Status::CODE_NOT_PUBLISHED, $history->new_value);
    }

    public function test_quarantine_hide_is_idempotent_no_duplicate_history_entry(): void
    {
        $shop = $this->makeShop('dagbars');
        $product = $this->makeProduct($shop, 'Безымянный', 1000);

        $this->import($product, []);
        $countAfterFirst = ProductHistory::where('product_id', $product->id)->count();

        $this->import($product, []);
        $countAfterSecond = ProductHistory::where('product_id', $product->id)->count();

        $this->assertSame(
            $countAfterFirst,
            $countAfterSecond,
            'Повторный карантин уже скрытого товара не должен плодить записи истории без реального изменения статуса'
        );
    }

    public function test_no_ean_is_idempotent_counter_does_not_inflate(): void
    {
        $shop = $this->makeShop('dagbars');
        $product = $this->makeProduct($shop, 'Безымянный', 1000);

        $this->import($product, []);
        $this->import($product, []);

        $this->assertSame(1, AggregatorQuarantine::query()->unresolved()->count());
    }

    public function test_double_match_goes_to_quarantine_no_offer_created(): void
    {
        // Две разные карточки с разными штрихкодами.
        $shopA = $this->makeShop('dagbars');
        $shopB = $this->makeShop('tsk');
        $this->import($this->makeProduct($shopA, 'Товар 1'), ['1111111111111']);
        $this->import($this->makeProduct($shopB, 'Товар 2'), ['2222222222222']);
        $this->assertDatabaseCount('platform_products', 2);

        // Новый товар третьего магазина матчит ОБА штрихкода → разные карточки → double_match.
        $shopC = $this->makeShop('eskimos');
        $productC = $this->makeProduct($shopC, 'Товар двойной');
        $this->import($productC, ['1111111111111', '2222222222222']);

        $this->assertDatabaseCount('platform_products', 2); // новых не создано
        $this->assertNull(ProductOffer::where('local_product_id', $productC->id)->first());

        $record = AggregatorQuarantine::where('local_product_id', $productC->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(AggregatorQuarantine::REASON_DOUBLE_MATCH, $record->reason);
        $this->assertCount(2, $record->matched_platform_product_ids);
    }

    public function test_reimport_is_idempotent_offer_updated_not_duplicated(): void
    {
        $shop = $this->makeShop('dagbars');
        $product = $this->makeProduct($shop, 'Молоко', 12990, 47);

        $this->import($product, ['4607034012345']);

        // Цена/остаток изменились в 1С → тот же оффер обновляется.
        Price::where('product_id', $product->id)->update(['value' => 13990]);
        Remain::where('product_id', $product->id)->update(['quantity' => 100]);

        $this->import($product->fresh(), ['4607034012345']);

        $this->assertDatabaseCount('product_offers', 1);
        $offer = ProductOffer::first();
        $this->assertSame(13990, $offer->price);
        $this->assertEquals(100.0, (float) $offer->qty);
    }

    public function test_ean_normalization_strips_non_digits_and_accepts_any_length(): void
    {
        $shop = $this->makeShop('dagbars');
        $product = $this->makeProduct($shop, 'Товар', 1000);

        // Нестандартная длина + мусорные символы — нормализуется в цифры, принимается.
        $this->import($product, ['  46-070 340/12 ', 'ABC']);

        $platform = PlatformProduct::first();
        $this->assertNotNull($platform);
        // '  46-070 340/12 ' → только цифры → '4607034012'; 'ABC' → '' → отброшен.
        $this->assertEqualsCanonicalizing(
            ['4607034012'],
            $platform->barcodes()->pluck('ean')->all()
        );
    }

    public function test_canonical_platform_card_does_not_create_offer(): void
    {
        $shop = $this->makeShop('dagbars');
        $product = $this->makeProduct($shop, 'Молоко платформенное', 12990, 47);

        // Canonical-карточка, вернувшаяся из Bagisto (payload sku='platform-1') — defense-in-depth
        // против петли (FOODLINK-10874). Оффер/платформа НЕ создаются.
        $this->import($product, ['4607034012345'], ['sku' => 'platform-1']);

        $this->assertDatabaseCount('platform_products', 0);
        $this->assertDatabaseCount('product_offers', 0);
    }
}
