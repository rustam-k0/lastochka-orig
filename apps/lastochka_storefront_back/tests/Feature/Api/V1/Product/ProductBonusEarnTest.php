<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Product;

use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Бейдж «+N бонусов» на карточке товара.
 *
 * Зеркалит правило начисления из CartResource/CreateOrderAction::previewBonus:
 * бонусы начисляются только за позиции без скидки (Product::hasDiscount()).
 */
final class ProductBonusEarnTest extends TestCase
{
    use RefreshDatabase;

    private function enableConfigurableProducts(): void
    {
        Cache::flush();
        StorefrontSetting::updateOrCreate(
            ['key' => 'project_modules'],
            ['value' => json_encode(['configurable_products' => ['enabled' => true]]), 'type' => 'json'],
        );
    }

    private function storeWithBonus(int $earnPercent = 5): Store
    {
        return Store::factory()->create([
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => $earnPercent,
            'bonus_spend_percent' => 20,
        ]);
    }

    public function test_bonus_earn_returned_for_product_without_discount(): void
    {
        $store = $this->storeWithBonus(5);
        $product = Product::factory()->for($store)->create([
            'price' => 1000,
            'price_old' => null,
            'quantity' => 10,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.bonusEarn', 50);
    }

    public function test_bonus_earn_is_floored(): void
    {
        $store = $this->storeWithBonus(5);
        $product = Product::factory()->for($store)->create([
            'price' => 999,
            'price_old' => null,
            'quantity' => 10,
        ]);

        // floor(999 * 5 / 100) = floor(49.95) = 49
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.bonusEarn', 49);
    }

    public function test_bonus_earn_is_zero_for_product_with_manual_discount(): void
    {
        $store = $this->storeWithBonus(5);
        $product = Product::factory()->for($store)->create([
            'price' => 800,
            'price_old' => 1000,
            'quantity' => 10,
        ]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.bonusEarn', 0);
    }

    public function test_bonus_earn_is_zero_for_product_with_active_promotion(): void
    {
        $store = $this->storeWithBonus(5);
        $product = Product::factory()->for($store)->create([
            'price' => 1000,
            'price_old' => null,
            'quantity' => 10,
        ]);
        $promotion = Promotion::factory()->for($store)->withDiscountPercent(20)->create();
        $promotion->products()->attach($product);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.bonusEarn', 0);
    }

    public function test_bonus_earn_is_zero_when_store_bonus_disabled(): void
    {
        $store = Store::factory()->create([
            'is_bonus_enabled' => false,
            'bonus_earn_percent' => 5,
        ]);
        $product = Product::factory()->for($store)->create([
            'price' => 1000,
            'price_old' => null,
            'quantity' => 10,
        ]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.bonusEarn', 0);
    }

    public function test_store_id_returned_on_product_show(): void
    {
        $store = $this->storeWithBonus();
        $product = Product::factory()->for($store)->create(['quantity' => 10]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.storeId', $store->id);
    }

    public function test_bonus_earn_returned_in_category_listing(): void
    {
        $store = $this->storeWithBonus(10);
        $category = Category::factory()->forStore($store)->create();
        Product::factory()->for($store)->inCategory($category)->create([
            'price' => 500,
            'price_old' => null,
            'quantity' => 10,
        ]);

        $this->getJson("/api/v1/stores/{$store->id}/categories/{$category->id}/products")
            ->assertOk()
            ->assertJsonPath('data.0.bonusEarn', 50)
            ->assertJsonPath('data.0.storeId', $store->id);
    }

    /**
     * Процент магазина резолвится через кеш запроса, поэтому число обращений
     * к `stores` не зависит от размера выдачи.
     */
    public function test_bonus_earn_does_not_query_store_per_product(): void
    {
        $store = $this->storeWithBonus(5);
        $category = Category::factory()->forStore($store)->create();

        $endpoint = "/api/v1/stores/{$store->id}/categories/{$category->id}/products?perPage=100";

        Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'price_old' => null,
            'quantity' => 10,
        ]);
        $withOneProduct = $this->countStoreQueries($endpoint, 1);

        Product::factory()->count(19)->for($store)->inCategory($category)->create([
            'price' => 100,
            'price_old' => null,
            'quantity' => 10,
        ]);
        $withTwentyProducts = $this->countStoreQueries($endpoint, 20);

        $this->assertSame(
            $withOneProduct,
            $withTwentyProducts,
            "Запросов к stores: {$withOneProduct} на 1 товар против {$withTwentyProducts} на 20 — bonusEarn создал N+1",
        );
    }

    /**
     * Число SELECT'ов к таблице stores при запросе выдачи.
     */
    private function countStoreQueries(string $endpoint, int $expectedProducts): int
    {
        // Прогрев: первый запрос наполняет кеши настроек, которые сами по себе
        // ходят в stores — иначе замер поймает их, а не bonusEarn.
        $this->getJson($endpoint)->assertOk();

        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (str_contains($query->sql, 'from "stores"')) {
                $count++;
            }
        });

        $this->getJson($endpoint)
            ->assertOk()
            ->assertJsonCount($expectedProducts, 'data');

        return $count;
    }

    public function test_bonus_earn_returned_for_each_variant(): void
    {
        $this->enableConfigurableProducts();

        $store = $this->storeWithBonus(5);
        $parent = Product::factory()->for($store)->create([
            'is_configurable' => true,
            'price' => 1000,
            'price_old' => null,
            'quantity' => 0,
        ]);
        $cheap = Product::factory()->for($store)->create([
            'parent_id' => $parent->id,
            'price' => 1000,
            'price_old' => null,
            'quantity' => 5,
        ]);
        $discounted = Product::factory()->for($store)->create([
            'parent_id' => $parent->id,
            'price' => 1600,
            'price_old' => 2000,
            'quantity' => 5,
        ]);

        $response = $this->getJson("/api/v1/products/{$parent->id}");

        $response->assertOk();
        $variants = collect($response->json('data.variants'))->keyBy('id');
        $this->assertSame(50, $variants[$cheap->id]['bonusEarn']);
        // Вариант со скидкой — бонусы не начисляются.
        $this->assertSame(0, $variants[$discounted->id]['bonusEarn']);
    }
}
