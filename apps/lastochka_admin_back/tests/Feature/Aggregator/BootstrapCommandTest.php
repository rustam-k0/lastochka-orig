<?php

namespace Tests\Feature\Aggregator;

use App\Models\AggregatorQuarantine;
use App\Models\PlatformProduct;
use App\Models\PlatformProductBarcode;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Remain;
use App\Models\Shop;
use App\Aggregator\Support\AggregatorMode;
use App\Models\AdminSetting;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Тесты команды aggregator:bootstrap (FOODLINK-10874, §11.1): union-find по штрихкодам.
 */
class BootstrapCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // bootstrap имеет runtime-гард по флагу — включаем режим для теста.
        AdminSetting::setValue('project_modules', json_encode([
            'aggregator_enabled' => ['label' => 'X', 'enabled' => true],
        ]), 'json');
        Cache::forget(AggregatorMode::CACHE_KEY);
    }

    private function makeShop(string $code): Shop
    {
        return Shop::create(['code' => $code, 'title' => $code]);
    }

    private function makeProduct(Shop $shop, string $title, array $barcodes, int $price = 1000, float $qty = 5): Product
    {
        $product = Product::withoutEvents(fn () => Product::create([
            'title' => $title,
            'articul' => uniqid('a_'),
            'shop_id' => $shop->id,
            'status_id' => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_PIECE,
            'barcodes' => $barcodes,
            'barcode' => $barcodes[0] ?? null,
        ]));
        Price::forceCreate(['product_id' => $product->id, 'type_id' => Type::PRICE_DEFAULT, 'value' => $price, 'step' => 1]);
        Remain::create(['product_id' => $product->id, 'shop_id' => $shop->id, 'quantity' => $qty]);
        return $product;
    }

    public function test_bootstrap_builds_components_transitively(): void
    {
        $a = $this->makeShop('a');
        $b = $this->makeShop('b');
        $c = $this->makeShop('c');
        $d = $this->makeShop('d');
        $e = $this->makeShop('e');

        // Компонента ABC через транзитивность: A(1) — B(1,2) — C(2).
        $this->makeProduct($a, 'Молоко A', ['1111111111111']);
        $this->makeProduct($b, 'Молоко простоквашино B', ['1111111111111', '2222222222222']);
        $this->makeProduct($c, 'Молоко C', ['2222222222222']);
        // Отдельная компонента D.
        $this->makeProduct($d, 'Хлеб D', ['3333333333333']);
        // Сирота без EAN.
        $this->makeProduct($e, 'Безымянный E', []);

        $this->artisan('aggregator:bootstrap')->assertSuccessful();

        // 2 платформенные карточки (ABC + D).
        $this->assertSame(2, PlatformProduct::count());

        // Карточка ABC: 2 штрихкода, 3 оффера (по магазину).
        $abc = PlatformProductBarcode::where('ean', '1111111111111')->first()->platformProduct;
        $this->assertEqualsCanonicalizing(
            ['1111111111111', '2222222222222'],
            $abc->barcodes()->pluck('ean')->all()
        );
        $this->assertSame(3, $abc->offers()->count());

        // canonical_title — самый длинный из группы.
        $this->assertSame('Молоко простоквашино B', $abc->canonical_title);

        // D — отдельная карточка с 1 оффером.
        $d_card = PlatformProductBarcode::where('ean', '3333333333333')->first()->platformProduct;
        $this->assertSame(1, $d_card->offers()->count());

        // Сирота → карантин no_ean, без оффера.
        $this->assertDatabaseHas('aggregator_quarantine', [
            'reason' => AggregatorQuarantine::REASON_NO_EAN,
            'resolved_at' => null,
        ]);
        $this->assertSame(4, ProductOffer::count());
    }

    public function test_bootstrap_dry_run_writes_nothing(): void
    {
        $a = $this->makeShop('a');
        $this->makeProduct($a, 'Молоко', ['1111111111111']);

        $this->artisan('aggregator:bootstrap --dry-run')->assertSuccessful();

        $this->assertSame(0, PlatformProduct::count());
        $this->assertSame(0, ProductOffer::count());
    }

    public function test_bootstrap_is_idempotent_on_rerun(): void
    {
        $a = $this->makeShop('a');
        $b = $this->makeShop('b');
        $this->makeProduct($a, 'Молоко A', ['1111111111111']);
        $this->makeProduct($b, 'Молоко B', ['1111111111111']);

        $this->artisan('aggregator:bootstrap')->assertSuccessful();
        $this->artisan('aggregator:bootstrap')->assertSuccessful();

        // Повторный прогон не плодит карточки/штрихкоды/офферы.
        $this->assertSame(1, PlatformProduct::count());
        $this->assertSame(1, PlatformProductBarcode::count());
        $this->assertSame(2, ProductOffer::count());
    }

    /**
     * Регрессия: товары с barcodes = NULL (как приходит из 1С без штрихкода)
     * должны попадать в карантин no_ean, а не молча игнорироваться выборкой.
     * До фикса loadProducts фильтровал whereNotNull('barcodes') → товары с NULL
     * не доходили до ветки $noEan и не карантинились (на dev так зависли 172 товара Эскимоса).
     */
    public function test_bootstrap_quarantines_products_with_null_barcodes(): void
    {
        $shop = $this->makeShop('a');

        $product = Product::withoutEvents(fn () => Product::create([
            'title' => 'Товар без штрихкода',
            'articul' => uniqid('a_'),
            'shop_id' => $shop->id,
            'status_id' => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_PIECE,
            'barcodes' => null,
            'barcode' => null,
        ]));

        $this->artisan('aggregator:bootstrap')->assertSuccessful();

        // Товар с NULL-штрихкодом виден оператору в карантине, оффер не создан.
        $this->assertDatabaseHas('aggregator_quarantine', [
            'local_product_id' => $product->id,
            'reason' => AggregatorQuarantine::REASON_NO_EAN,
            'resolved_at' => null,
        ]);
        $this->assertSame(0, PlatformProduct::count());
        $this->assertSame(0, ProductOffer::count());
    }
}
