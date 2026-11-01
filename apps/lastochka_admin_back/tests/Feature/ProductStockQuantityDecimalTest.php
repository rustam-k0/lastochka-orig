<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Остаток в карточке товара — в базовой единице 1С (кг/л/шт) с дробной частью.
 *
 * Прод 14.10: форма слала parseInt, а ProductUpdate принимал quantity: Int —
 * любое сохранение карточки весового товара обрезало остаток (4.874 → 4,
 * 0.828 → 0 и товар пропадал с витрины до выгрузки 1С). Новое поле
 * stock_quantity: Float; старое quantity: Int оставлено для совместимости
 * (админка до своего деплоя шлёт Int-переменную).
 */
class ProductStockQuantityDecimalTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private User $admin;
    private Shop $shop;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', Role::ADMIN))->firstOrFail();

        $this->shop = Shop::create([
            'title' => 'Decimal stock shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $this->category = Category::create([
            'title' => 'Decimal stock category ' . uniqid(),
            'parent_id' => null,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'title' => 'Филе грудки ' . uniqid(),
            'shop_id' => $this->shop->id,
            'info_weight' => 300,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
        ]);
    }

    private function remain(Product $product): float
    {
        return (float) Remain::where('product_id', $product->id)
            ->where('shop_id', $this->shop->id)
            ->value('quantity');
    }

    /** @test */
    public function product_update_keeps_fractional_stock_quantity(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin)->graphQL(<<<'GQL'
            mutation($id: Int!, $title: String!, $price: Float!, $stock_quantity: Float, $category_ids: [Int!], $shop_id: Int!, $info_weight_type_id: Int!) {
              ProductUpdate(
                id: $id, title: $title, price: $price, stock_quantity: $stock_quantity,
                category_ids: $category_ids, shop_id: $shop_id, info_weight_type_id: $info_weight_type_id
              ) { code message }
            }
        GQL, [
            'id' => $product->id,
            'title' => $product->title,
            'price' => 178.2,
            'stock_quantity' => 12.806,
            'category_ids' => [$this->category->id],
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
        ]);

        $response->assertJson(['data' => ['ProductUpdate' => ['code' => 200]]]);
        $this->assertSame(12.806, $this->remain($product));
    }

    /** @test */
    public function legacy_integer_quantity_is_still_accepted(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin)->graphQL(<<<'GQL'
            mutation($id: Int!, $title: String!, $price: Float!, $quantity: Int, $category_ids: [Int!], $shop_id: Int!, $info_weight_type_id: Int!) {
              ProductUpdate(
                id: $id, title: $title, price: $price, quantity: $quantity,
                category_ids: $category_ids, shop_id: $shop_id, info_weight_type_id: $info_weight_type_id
              ) { code message }
            }
        GQL, [
            'id' => $product->id,
            'title' => $product->title,
            'price' => 178.2,
            'quantity' => 7,
            'category_ids' => [$this->category->id],
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
        ]);

        $response->assertJson(['data' => ['ProductUpdate' => ['code' => 200]]]);
        $this->assertSame(7.0, $this->remain($product));
    }

    /** @test */
    public function negative_stock_quantity_is_saved_as_zero(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin)->graphQL(<<<'GQL'
            mutation($id: Int!, $title: String!, $price: Float!, $stock_quantity: Float, $category_ids: [Int!], $shop_id: Int!, $info_weight_type_id: Int!) {
              ProductUpdate(
                id: $id, title: $title, price: $price, stock_quantity: $stock_quantity,
                category_ids: $category_ids, shop_id: $shop_id, info_weight_type_id: $info_weight_type_id
              ) { code message }
            }
        GQL, [
            'id' => $product->id,
            'title' => $product->title,
            'price' => 178.2,
            'stock_quantity' => -1.5,
            'category_ids' => [$this->category->id],
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
        ])->assertJson(['data' => ['ProductUpdate' => ['code' => 200]]]);

        $this->assertSame(0.0, $this->remain($product));
    }

    /** @test */
    public function product_update_without_price_keeps_portion_unit(): void
    {
        // Форма шлёт в info_weight_type_id базовую единицу (поле «Ед. измерения»
        // у товара с базой 1С — только чтение). В сетевом каталоге цена в
        // карточке не передаётся, пересчёт фасовки не вызывается, и порция
        // становилась «300 кг».
        \Illuminate\Support\Facades\Cache::forever(\App\NetworkCatalog\Support\NetworkCatalogMode::CACHE_KEY, true);
        $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\Cache::forget(\App\NetworkCatalog\Support\NetworkCatalogMode::CACHE_KEY));
        $product = $this->product();

        $this->actingAs($this->admin)->graphQL(<<<'GQL'
            mutation($id: Int!, $title: String!, $shop_id: Int!, $info_weight_type_id: Int!) {
              ProductUpdate(id: $id, title: $title, shop_id: $shop_id, info_weight_type_id: $info_weight_type_id) { code message }
            }
        GQL, [
            'id' => $product->id,
            'title' => $product->title,
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
        ])->assertJson(['data' => ['ProductUpdate' => ['code' => 200]]]);

        $product->refresh();
        $this->assertSame(Type::INFO_WEIGHT_GRAM, (int) $product->info_weight_type_id);
        $this->assertSame(300, (int) $product->info_weight);
    }
}
