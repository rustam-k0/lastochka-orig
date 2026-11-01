<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Category;
use App\Models\Comparison;
use App\Models\ComparisonItem;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

final class PriceTiersEstimateComparisonTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private const FROM_ONE = [
        ['min_quantity' => 1, 'price' => 279],
        ['min_quantity' => 2, 'price' => 249],
        ['min_quantity' => 5, 'price' => 199],
    ];

    private User $user;

    private Store $store;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['is_active' => true]);
        $this->category = Category::factory()->forStore($this->store)->create(['is_active' => true]);
        $this->product = Product::factory()->for($this->store)->inCategory($this->category)->create([
            'price' => 300, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece',
        ]);
        $this->user = User::factory()->create();
    }

    private function estimateWith(int $quantity): Estimate
    {
        $estimate = Estimate::factory()->create(['user_id' => $this->user->id]);
        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
        ]);

        return $estimate;
    }

    /**
     * Товар в том же магазине с собственной лесенкой (для теста пачечной
     * подгрузки нескольких РАЗНЫХ товаров одним запросом).
     *
     * @param  array<int, array{min_quantity: int|float, price: int|float}>  $tiers
     */
    private function createProductWithTiers(array $tiers = self::FROM_ONE): Product
    {
        $product = Product::factory()->for($this->store)->inCategory($this->category)->create([
            'price' => 300, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece',
        ]);
        $this->seedPriceTiers($product, (int) $product->store_id, $tiers);

        return $product;
    }

    /**
     * Смета с несколькими позициями разных товаров.
     *
     * @param  list<array{0: Product, 1: int}>  $productQuantities
     */
    private function estimateWithProducts(array $productQuantities): Estimate
    {
        $estimate = Estimate::factory()->create(['user_id' => $this->user->id]);

        foreach ($productQuantities as [$product, $quantity]) {
            EstimateItem::factory()->create([
                'estimate_id' => $estimate->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        }

        return $estimate;
    }

    /**
     * Смета минимум с тремя РАЗНЫМИ товарами, у каждого своя лесенка.
     *
     * @return array{0: Estimate, 1: Product, 2: Product, 3: Product}
     */
    private function estimateWithThreeDifferentProductsAndTiers(): array
    {
        $productA = $this->product;
        $this->seedPriceTiers($productA, (int) $productA->store_id, self::FROM_ONE);
        $productB = $this->createProductWithTiers();
        $productC = $this->createProductWithTiers();

        $estimate = $this->estimateWithProducts([
            [$productA, 5],
            [$productB, 3],
            [$productC, 2],
        ]);

        return [$estimate, $productA, $productB, $productC];
    }

    public function test_estimate_total_uses_tier_of_item_quantity(): void
    {
        $this->setPriceTiersModule(true);
        $this->seedPriceTiers($this->product, (int) $this->product->store_id, self::FROM_ONE);
        $estimate = $this->estimateWith(5);

        $response = $this->actingAs($this->user)->getJson("/api/v1/estimates/{$estimate->id}")->assertOk();

        $this->assertSame(995, (int) $response->json('data.totalAmount'), '5 шт по ступени 199');
        $this->assertEquals(279.0, (float) $response->json('data.items.0.product.price'), 'карточка: цена за одну порцию');
    }

    public function test_shared_estimate_uses_tier_too(): void
    {
        $this->setPriceTiersModule(true);
        $this->seedPriceTiers($this->product, (int) $this->product->store_id, self::FROM_ONE);
        $estimate = $this->estimateWith(2);

        $token = $this->actingAs($this->user)->postJson("/api/v1/estimates/{$estimate->id}/share")->json('shareToken');

        $this->assertSame(498, (int) $this->getJson("/api/v1/estimates/shared/{$token}")->assertOk()->json('data.totalAmount'));
    }

    public function test_estimate_total_is_unchanged_when_flag_is_off(): void
    {
        $this->setPriceTiersModule(false);
        $this->seedPriceTiers($this->product, (int) $this->product->store_id, self::FROM_ONE);
        $estimate = $this->estimateWith(5);

        $response = $this->actingAs($this->user)->getJson("/api/v1/estimates/{$estimate->id}")->assertOk();

        $this->assertSame(1500, (int) $response->json('data.totalAmount'));
    }

    public function test_comparison_shows_price_for_one_portion(): void
    {
        $this->setPriceTiersModule(true);
        $this->seedPriceTiers($this->product, (int) $this->product->store_id, self::FROM_ONE);

        $comparison = Comparison::factory()->create(['user_id' => $this->user->id, 'category_id' => $this->category->id]);
        ComparisonItem::factory()->create(['comparison_id' => $comparison->id, 'product_id' => $this->product->id]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/comparisons/{$this->category->id}")->assertOk();

        $this->assertEquals(279.0, (float) $response->json('data.products.0.price'));
    }

    public function test_comparison_price_is_unchanged_when_flag_is_off(): void
    {
        $this->setPriceTiersModule(false);
        $this->seedPriceTiers($this->product, (int) $this->product->store_id, self::FROM_ONE);

        $comparison = Comparison::factory()->create(['user_id' => $this->user->id, 'category_id' => $this->category->id]);
        ComparisonItem::factory()->create(['comparison_id' => $comparison->id, 'product_id' => $this->product->id]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/comparisons/{$this->category->id}")->assertOk();

        $this->assertEquals(300.0, (float) $response->json('data.products.0.price'));
    }

    // ==================== Пачечная подгрузка лесенок (находка ревью Task 13) ====================
    //
    // EstimateItemResource рендерит товар через `new ProductResource(...)` на каждый
    // элемент, мимо переопределённого ProductResource::collection() с батч-подгрузкой.
    // Смета с N разными товарами при включённом флаге не должна давать N запросов
    // к product_price_tiers — только один пачечный. Проверяем на всех поверхностях,
    // где рендерятся товары сметы.

    public function test_estimate_json_batches_price_tier_queries_regardless_of_item_count(): void
    {
        $this->setPriceTiersModule(true);
        [$estimate] = $this->estimateWithThreeDifferentProductsAndTiers();

        $queries = $this->priceTierTableQueries(function () use ($estimate): void {
            $this->actingAs($this->user)->getJson("/api/v1/estimates/{$estimate->id}")->assertOk();
        });

        $this->assertSame(1, $queries, 'лесенки трёх разных товаров сметы должны читаться одним пачечным запросом');
    }

    public function test_estimate_list_batches_price_tier_queries_regardless_of_item_count(): void
    {
        $this->setPriceTiersModule(true);
        $this->estimateWithThreeDifferentProductsAndTiers();

        $queries = $this->priceTierTableQueries(function (): void {
            $this->actingAs($this->user)->getJson('/api/v1/estimates')->assertOk();
        });

        $this->assertSame(1, $queries, 'список смет: лесенки товаров читаются одним пачечным запросом');
    }

    public function test_shared_estimate_json_batches_price_tier_queries_regardless_of_item_count(): void
    {
        $this->setPriceTiersModule(true);
        [$estimate] = $this->estimateWithThreeDifferentProductsAndTiers();
        $token = $this->actingAs($this->user)->postJson("/api/v1/estimates/{$estimate->id}/share")->json('shareToken');

        $queries = $this->priceTierTableQueries(function () use ($token): void {
            $this->getJson("/api/v1/estimates/shared/{$token}")->assertOk();
        });

        $this->assertSame(1, $queries, 'публичная смета (API): один пачечный запрос независимо от числа товаров');
    }

    public function test_shared_estimate_html_page_batches_price_tier_queries_regardless_of_item_count(): void
    {
        $this->setPriceTiersModule(true);
        [$estimate] = $this->estimateWithThreeDifferentProductsAndTiers();
        $token = $this->actingAs($this->user)->postJson("/api/v1/estimates/{$estimate->id}/share")->json('shareToken');

        $queries = $this->priceTierTableQueries(function () use ($token): void {
            $this->get("/estimates/shared/{$token}")->assertOk();
        });

        $this->assertSame(1, $queries, 'HTML-страница публичной сметы: один пачечный запрос независимо от числа товаров');
    }

    public function test_estimate_xlsx_export_batches_price_tier_queries_regardless_of_item_count(): void
    {
        $this->setPriceTiersModule(true);
        [$estimate] = $this->estimateWithThreeDifferentProductsAndTiers();

        $queries = $this->priceTierTableQueries(function () use ($estimate): void {
            $this->actingAs($this->user)->get("/api/v1/estimates/{$estimate->id}/export?format=xlsx")->assertOk();
        });

        $this->assertSame(1, $queries, 'выгрузка XLSX: один пачечный запрос независимо от числа товаров');
    }

    public function test_estimate_pdf_export_batches_price_tier_queries_regardless_of_item_count(): void
    {
        $this->setPriceTiersModule(true);
        [$estimate] = $this->estimateWithThreeDifferentProductsAndTiers();

        $queries = $this->priceTierTableQueries(function () use ($estimate): void {
            $this->actingAs($this->user)->get("/api/v1/estimates/{$estimate->id}/export?format=pdf")->assertOk();
        });

        $this->assertSame(1, $queries, 'выгрузка PDF: один пачечный запрос независимо от числа товаров');
    }

    public function test_estimate_and_comparison_surfaces_make_no_price_tier_queries_when_flag_is_off(): void
    {
        $this->setPriceTiersModule(false);
        [$estimate, $productA, $productB, $productC] = $this->estimateWithThreeDifferentProductsAndTiers();
        $token = $this->actingAs($this->user)->postJson("/api/v1/estimates/{$estimate->id}/share")->json('shareToken');

        $comparison = Comparison::factory()->create(['user_id' => $this->user->id, 'category_id' => $this->category->id]);
        foreach ([$productA, $productB, $productC] as $product) {
            ComparisonItem::factory()->create(['comparison_id' => $comparison->id, 'product_id' => $product->id]);
        }

        $queries = $this->priceTierTableQueries(function () use ($estimate, $token): void {
            $this->actingAs($this->user)->getJson("/api/v1/estimates/{$estimate->id}")->assertOk();
            $this->actingAs($this->user)->getJson('/api/v1/estimates')->assertOk();
            $this->getJson("/api/v1/estimates/shared/{$token}")->assertOk();
            $this->get("/estimates/shared/{$token}")->assertOk();
            $this->actingAs($this->user)->get("/api/v1/estimates/{$estimate->id}/export?format=xlsx")->assertOk();
            $this->actingAs($this->user)->get("/api/v1/estimates/{$estimate->id}/export?format=pdf")->assertOk();
            $this->actingAs($this->user)->getJson("/api/v1/comparisons/{$this->category->id}")->assertOk();
        });

        $this->assertSame(0, $queries, 'при выключенном флаге ни одна поверхность сметы/сравнения не обращается к product_price_tiers');
    }

    public function test_comparison_batches_price_tier_queries_regardless_of_item_count(): void
    {
        $this->setPriceTiersModule(true);

        // У каждого товара своя лесенка — цена в ответе должна соответствовать
        // именно его ступени, а не случайно совпасть с чужой (проверка того,
        // что предзагрузка кладёт лесенки по правильным парам магазин/товар).
        $productA = $this->product;
        $this->seedPriceTiers($productA, (int) $productA->store_id, self::FROM_ONE);
        $productB = $this->createProductWithTiers([
            ['min_quantity' => 1, 'price' => 260],
            ['min_quantity' => 3, 'price' => 230],
        ]);
        $productC = $this->createProductWithTiers([
            ['min_quantity' => 1, 'price' => 240],
        ]);

        $comparison = Comparison::factory()->create(['user_id' => $this->user->id, 'category_id' => $this->category->id]);
        foreach ([$productA, $productB, $productC] as $product) {
            ComparisonItem::factory()->create(['comparison_id' => $comparison->id, 'product_id' => $product->id]);
        }

        $response = null;
        $queries = $this->priceTierTableQueries(function () use (&$response): void {
            $response = $this->actingAs($this->user)->getJson("/api/v1/comparisons/{$this->category->id}")->assertOk();
        });

        $this->assertSame(1, $queries, 'лесенки трёх разных товаров сравнения должны читаться одним пачечным запросом');

        $products = collect($response->json('data.products'))->keyBy('id');
        $this->assertEquals(279.0, (float) $products[$productA->id]['price'], 'товар A: цена за одну порцию по его лесенке');
        $this->assertEquals(260.0, (float) $products[$productB->id]['price'], 'товар B: цена за одну порцию по его лесенке');
        $this->assertEquals(240.0, (float) $products[$productC->id]['price'], 'товар C: цена за одну порцию по его лесенке');
    }
}
