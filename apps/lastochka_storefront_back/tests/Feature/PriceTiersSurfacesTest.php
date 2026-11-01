<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comparison;
use App\Models\ComparisonItem;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductGroupItem;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

/**
 * Цены от количества на ВСЕХ поверхностях цены разом (образец:
 * NetworkCatalogPriceSurfacesTest). Обычная цена товара 300, лесенка
 * «от 1: 279, от 5: 199». Контракт: каждая поверхность, показывающая цену за
 * одну порцию, отдаёт 279 и одинаковую лесенку; корзина и смета считают по
 * количеству; при выключенном модуле везде 300 и priceTiers: [].
 */
final class PriceTiersSurfacesTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private const LADDER = [
        ['min_quantity' => 1, 'price' => 279],
        ['min_quantity' => 5, 'price' => 199],
    ];

    private const BARCODE = '4600000003081';

    private Store $store;

    private Category $category;

    private Product $product;

    private User $user;

    /** Обычный режим: товар принадлежит магазину, цена в колонках products. */
    private function seedRegular(): void
    {
        $this->store = Store::factory()->create(['code' => 'tiers-surf', 'is_active' => true]);
        $this->category = Category::factory()->forStore($this->store)->create(['is_active' => true]);
        $this->product = Product::factory()->for($this->store)->inCategory($this->category)->create([
            'is_active' => true, 'price' => 300, 'quantity' => 50, 'quantity_step' => 1,
            'measurement_unit' => 'piece', 'barcodes' => [self::BARCODE],
        ]);
        $this->user = User::factory()->create();
    }

    /** Сетевой каталог: карточка в якорном магазине, цена в оффере магазина контекста. */
    private function seedNetwork(): void
    {
        $anchor = Store::factory()->create(['code' => 'tiers-surf-anchor', 'is_active' => true]);
        $this->store = Store::factory()->create(['code' => 'tiers-surf-shop', 'is_active' => true]);
        $this->category = Category::factory()->forStore($this->store)->create(['is_active' => true]);
        $this->product = Product::factory()->inCategory($this->category)->create([
            'store_id' => $anchor->id, 'is_active' => true, 'price' => 0, 'quantity' => 0, 'quantity_step' => 1,
            'measurement_unit' => 'piece', 'barcodes' => [self::BARCODE],
        ]);
        StoreProduct::create([
            'store_id' => $this->store->id, 'product_id' => $this->product->id, 'price' => 300, 'quantity' => 50,
        ]);
        $this->user = User::factory()->create();
    }

    /**
     * Цена товара за одну порцию и его лесенка на каждой поверхности.
     *
     * @return array<string, array{price: float, tiers: array<int, mixed>|null}>
     */
    private function productSurfaces(string $query): array
    {
        $group = ProductGroup::factory()->create(['store_id' => $this->store->id, 'is_active' => true]);
        ProductGroupItem::factory()->create(['product_group_id' => $group->id, 'product_id' => $this->product->id, 'position' => 0]);
        Favorite::factory()->create(['user_id' => $this->user->id, 'product_id' => $this->product->id]);
        $comparison = Comparison::factory()->create(['user_id' => $this->user->id, 'category_id' => $this->category->id]);
        ComparisonItem::factory()->create(['comparison_id' => $comparison->id, 'product_id' => $this->product->id]);

        $card = $this->getJson("/api/v1/products/{$this->product->id}{$query}")->assertOk()->json('data');
        $listing = $this->getJson("/api/v1/stores/{$this->store->id}/categories/{$this->category->id}/products")->assertOk()->json('data.0');
        $tree = collect($this->getJson("/api/v1/stores/{$this->store->id}/categories?includeChildren=1&includeProducts=1")->assertOk()->json('data'))
            ->firstWhere('id', $this->category->id)['products'][0];
        $barcode = $this->getJson('/api/v1/products/search/barcode/'.self::BARCODE.$query)->assertOk()->json('data');
        $groupProduct = $this->getJson("/api/v1/product-groups/{$group->id}/products")->assertOk()->json('data.0');
        $favorite = $this->actingAs($this->user)->getJson("/api/v1/favorites{$query}")->assertOk()->json('data.0');
        $compared = $this->actingAs($this->user)->getJson("/api/v1/comparisons/{$this->category->id}{$query}")->assertOk()->json('data.products.0');

        return [
            'card' => ['price' => (float) $card['price'], 'tiers' => $card['priceTiers']],
            'listing' => ['price' => (float) $listing['price'], 'tiers' => $listing['priceTiers']],
            'category tree' => ['price' => (float) $tree['price'], 'tiers' => $tree['priceTiers']],
            'barcode' => ['price' => (float) $barcode['price'], 'tiers' => $barcode['priceTiers']],
            'product group' => ['price' => (float) $groupProduct['price'], 'tiers' => $groupProduct['priceTiers']],
            'favorites' => ['price' => (float) $favorite['price'], 'tiers' => $favorite['priceTiers']],
            // Сравнение отдаёт только цену: лесенки в его контракте нет.
            'comparison' => ['price' => (float) $compared['price'], 'tiers' => null],
        ];
    }

    private function assertSurfaces(array $surfaces, float $price, array $tiers): void
    {
        foreach ($surfaces as $name => $surface) {
            $this->assertEquals($price, $surface['price'], "цена на поверхности «{$name}»");

            if ($surface['tiers'] !== null) {
                $this->assertEquals($tiers, $surface['tiers'], "лесенка на поверхности «{$name}»");
            }
        }
    }

    /** Корзина (5 шт) и смета (5 шт): цена по количеству. */
    private function quantitySurfaces(string $query, array $cartPayload): array
    {
        $cart = $this->actingAs($this->user)->postJson('/api/v1/cart/items', $cartPayload + [
            'productId' => $this->product->id,
            'quantity' => 5,
        ])->assertOk()->json('storeGroups.0.items.0');

        $estimate = Estimate::factory()->create(['user_id' => $this->user->id]);
        EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'product_id' => $this->product->id, 'quantity' => 5]);
        $estimateTotal = (int) $this->actingAs($this->user)->getJson("/api/v1/estimates/{$estimate->id}{$query}")->assertOk()->json('data.totalAmount');

        return ['cartPrice' => (float) $cart['price'], 'cartTiers' => $cart['priceTiers'], 'estimateTotal' => $estimateTotal];
    }

    public function test_regular_mode_all_surfaces_agree(): void
    {
        $this->setPriceTiersModule(true);
        $this->seedRegular();
        $this->seedPriceTiers($this->product, $this->store->id, self::LADDER);

        $expectedTiers = [['minQuantity' => 1, 'price' => 279], ['minQuantity' => 5, 'price' => 199]];

        $this->assertSurfaces($this->productSurfaces(''), 279.0, $expectedTiers);

        $byQuantity = $this->quantitySurfaces('', []);
        $this->assertSame(199.0, $byQuantity['cartPrice']);
        $this->assertEquals($expectedTiers, $byQuantity['cartTiers']);
        $this->assertSame(995, $byQuantity['estimateTotal']);
    }

    public function test_network_mode_all_surfaces_agree(): void
    {
        $this->setPriceTiersModule(true, networkCatalog: true);
        $this->seedNetwork();
        $this->seedPriceTiers($this->product, $this->store->id, self::LADDER);

        $expectedTiers = [['minQuantity' => 1, 'price' => 279], ['minQuantity' => 5, 'price' => 199]];
        $query = "?storeId={$this->store->id}";

        $this->assertSurfaces($this->productSurfaces($query), 279.0, $expectedTiers);

        $byQuantity = $this->quantitySurfaces($query, ['storeId' => $this->store->id]);
        $this->assertSame(199.0, $byQuantity['cartPrice']);
        $this->assertEquals($expectedTiers, $byQuantity['cartTiers']);
        $this->assertSame(995, $byQuantity['estimateTotal']);
    }

    /** Флаг выключен: лесенка в таблице есть, но API её не видит, цены как раньше. */
    public function test_flag_off_surfaces_are_unchanged(): void
    {
        $this->setPriceTiersModule(false);
        $this->seedRegular();
        $this->seedPriceTiers($this->product, $this->store->id, self::LADDER);

        $surfaces = [];
        $byQuantity = [];
        $queries = $this->priceTierTableQueries(function () use (&$surfaces, &$byQuantity): void {
            $surfaces = $this->productSurfaces('');
            $byQuantity = $this->quantitySurfaces('', []);
            $this->actingAs($this->user)->getJson('/api/v1/cart')->assertOk();
        });

        $this->assertSame(0, $queries, 'при выключенном флаге ни одна поверхность не обращается к таблице ступеней');
        $this->assertSurfaces($surfaces, 300.0, []);
        $this->assertSame(300.0, $byQuantity['cartPrice']);
        $this->assertSame([], $byQuantity['cartTiers']);
        $this->assertSame(1500, $byQuantity['estimateTotal']);

        $this->getJson('/api/v1/app-settings')->assertOk()->assertJsonPath('data.priceTiers', false);
    }

    public function test_network_mode_flag_off_surfaces_are_unchanged(): void
    {
        $this->setPriceTiersModule(false, networkCatalog: true);
        $this->seedNetwork();
        $this->seedPriceTiers($this->product, $this->store->id, self::LADDER);

        $query = "?storeId={$this->store->id}";

        $surfaces = [];
        $cartPrice = null;
        $queries = $this->priceTierTableQueries(function () use (&$surfaces, &$cartPrice, $query): void {
            $surfaces = $this->productSurfaces($query);
            $cartPrice = $this->quantitySurfaces($query, ['storeId' => $this->store->id])['cartPrice'];
        });

        $this->assertSame(0, $queries);
        $this->assertSurfaces($surfaces, 300.0, []);
        $this->assertSame(300.0, $cartPrice);
    }
}
