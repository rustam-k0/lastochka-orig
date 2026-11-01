<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\MerchantSale;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MerchantSaleTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop;
    protected Product $product1;
    protected Product $product2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();

        $this->shop = Shop::create([
            'title' => 'Test Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $this->product1 = Product::create([
            'title' => 'Test Product 1 ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);

        $this->product2 = Product::create([
            'title' => 'Test Product 2 ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
    }

    protected function createShop(?int $sphereId = null): Shop
    {
        return Shop::create([
            'title' => 'Test Shop ' . uniqid(),
            'sphere_id' => $sphereId ?? $this->shop->sphere_id,
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    protected function createProduct(int $shopId): Product
    {
        return Product::create([
            'title' => 'Test Product ' . uniqid(),
            'shop_id' => $shopId,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
    }

    protected function createSale(array $attributes = []): MerchantSale
    {
        return MerchantSale::create(array_merge([
            'shop_id' => $this->shop->id,
            'title' => 'Test Sale ' . uniqid(),
            'discount_percent' => 10,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addWeek(),
            'is_active' => true,
        ], $attributes));
    }

    /** @test */
    public function it_can_create_merchant_sale()
    {
        $mutation = '
            mutation createMerchantSale($input: CreateMerchantSaleInput!) {
                createMerchantSale(input: $input) {
                    id
                    title
                    discount_percent
                    status
                    products {
                        id
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Тестовая акция',
                    'shop_id' => $this->shop->id,
                    'discount_percent' => 20,
                    'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                    'ends_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
                    'product_ids' => [$this->product1->id, $this->product2->id],
                ],
            ]);

        $response->assertJson([
            'data' => [
                'createMerchantSale' => [
                    'title' => 'Тестовая акция',
                    'discount_percent' => 20,
                    'status' => 'UPCOMING',
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_sales', [
            'title' => 'Тестовая акция',
            'discount_percent' => 20,
        ]);

        $this->assertDatabaseHas('merchant_sale_products', [
            'product_id' => $this->product1->id,
        ]);
    }

    /** @test */
    public function it_validates_product_belongs_to_shop()
    {
        $anotherShop = $this->createShop();
        $productFromAnotherShop = $this->createProduct($anotherShop->id);

        $mutation = '
            mutation createMerchantSale($input: CreateMerchantSaleInput!) {
                createMerchantSale(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Тестовая акция',
                    'shop_id' => $this->shop->id,
                    'discount_percent' => 20,
                    'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                    'ends_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
                    'product_ids' => [$productFromAnotherShop->id],
                ],
            ]);

        $response->assertGraphQLValidationKeys(['input.product_ids']);
    }

    /** @test */
    public function it_validates_ends_at_after_starts_at()
    {
        $mutation = '
            mutation createMerchantSale($input: CreateMerchantSaleInput!) {
                createMerchantSale(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Тестовая акция',
                    'shop_id' => $this->shop->id,
                    'discount_percent' => 20,
                    'starts_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
                    'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
                    'product_ids' => [$this->product1->id],
                ],
            ]);

        $response->assertGraphQLValidationKeys(['input.ends_at']);
    }

    /** @test */
    public function it_can_update_merchant_sale()
    {
        $sale = $this->createSale(['title' => 'Старое название', 'discount_percent' => 10]);

        $sale->products()->attach([$this->product1->id]);

        $mutation = '
            mutation updateMerchantSale($id: Int!, $input: UpdateMerchantSaleInput!) {
                updateMerchantSale(id: $id, input: $input) {
                    id
                    title
                    discount_percent
                    products {
                        id
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $sale->id,
                'input' => [
                    'title' => 'Новое название',
                    'discount_percent' => 25,
                    'product_ids' => [$this->product1->id, $this->product2->id],
                ],
            ]);

        $response->assertJson([
            'data' => [
                'updateMerchantSale' => [
                    'title' => 'Новое название',
                    'discount_percent' => 25,
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_sales', [
            'id' => $sale->id,
            'title' => 'Новое название',
            'discount_percent' => 25,
        ]);
    }

    /** @test */
    public function it_can_delete_merchant_sale()
    {
        $sale = $this->createSale();

        $mutation = '
            mutation deleteMerchantSale($id: Int!) {
                deleteMerchantSale(id: $id) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $sale->id,
            ]);

        $response->assertJson([
            'data' => [
                'deleteMerchantSale' => [
                    'id' => $sale->id,
                ],
            ],
        ]);

        $this->assertSoftDeleted('merchant_sales', [
            'id' => $sale->id,
        ]);
    }

    /** @test */
    public function it_calculates_correct_status()
    {
        $upcomingSale = MerchantSale::create([
            'title' => 'Upcoming',
            'shop_id' => $this->shop->id,
            'discount_percent' => 10,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(7),
            'is_active' => true,
        ]);

        $activeSale = MerchantSale::create([
            'title' => 'Active',
            'shop_id' => $this->shop->id,
            'discount_percent' => 15,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(7),
            'is_active' => true,
        ]);

        $expiredSale = MerchantSale::create([
            'title' => 'Expired',
            'shop_id' => $this->shop->id,
            'discount_percent' => 20,
            'starts_at' => now()->subDays(7),
            'ends_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $this->assertEquals(MerchantSale::STATUS_UPCOMING, $upcomingSale->status);
        $this->assertEquals(MerchantSale::STATUS_ACTIVE, $activeSale->status);
        $this->assertEquals(MerchantSale::STATUS_EXPIRED, $expiredSale->status);
    }

    /** @test */
    public function it_validates_discount_percent_range()
    {
        $mutation = '
            mutation createMerchantSale($input: CreateMerchantSaleInput!) {
                createMerchantSale(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'input' => [
                    'title' => 'Тестовая акция',
                    'shop_id' => $this->shop->id,
                    'discount_percent' => 150,
                    'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                    'ends_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
                    'product_ids' => [$this->product1->id],
                ],
            ]);

        $response->assertGraphQLValidationKeys(['input.discount_percent']);
    }
}
