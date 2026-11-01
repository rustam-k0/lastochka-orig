<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Regression: remains.quantity раньше была float(8,2) unsigned (max 999999.99)
 * — ProductUpdate падал с SQLSTATE[22003] при складских остатках от 1 тонны
 * (1 000 000 г). После миграции на unsignedBigInteger всё, что влезает в INT,
 * должно сохраняться без ошибок.
 */
class RemainQuantityLargeValueTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;
    protected Shop $shop;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();

        $this->shop = Shop::create([
            'title' => 'Remain Quantity Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $this->category = Category::create([
            'title' => 'Remain Category ' . uniqid(),
            'parent_id' => null,
        ]);
    }

    /** @test */
    public function remain_stores_values_above_one_million(): void
    {
        $product = Product::create([
            'title' => 'Bananas kg ' . uniqid(),
            'shop_id' => $this->shop->id,
            'info_weight' => 500,
            'info_weight_type_id' => \App\Models\Type::INFO_WEIGHT_GRAM,
        ]);

        $remain = Remain::updateOrCreate(
            ['product_id' => $product->id, 'shop_id' => $this->shop->id],
            ['quantity' => 1_000_000]
        );

        $this->assertSame(1_000_000, (int) $remain->fresh()->quantity);
    }

    /** @test */
    public function product_update_mutation_accepts_one_million_quantity(): void
    {
        $product = Product::create([
            'title' => 'Bananas kg ' . uniqid(),
            'shop_id' => $this->shop->id,
            'info_weight' => 500,
            'info_weight_type_id' => \App\Models\Type::INFO_WEIGHT_GRAM,
        ]);

        $mutation = <<<'GQL'
            mutation($id: Int!, $title: String!, $price: Float!, $quantity: Int, $category_ids: [Int!], $shop_id: Int!, $info_weight_type_id: Int!) {
              ProductUpdate(
                id: $id,
                title: $title,
                price: $price,
                quantity: $quantity,
                category_ids: $category_ids,
                shop_id: $shop_id,
                info_weight_type_id: $info_weight_type_id
              ) { code message }
            }
        GQL;

        $response = $this->actingAs($this->adminUser)->graphQL($mutation, [
            'id' => $product->id,
            'title' => $product->title,
            'price' => 100,
            'quantity' => 1_000_000,
            'category_ids' => [$this->category->id],
            'shop_id' => $this->shop->id,
            'info_weight_type_id' => \App\Models\Type::INFO_WEIGHT_GRAM,
        ]);

        $response->assertJson(['data' => ['ProductUpdate' => ['code' => 200]]]);

        $remain = Remain::where('product_id', $product->id)
            ->where('shop_id', $this->shop->id)
            ->firstOrFail();

        $this->assertSame(1_000_000, (int) $remain->quantity);
    }
}
