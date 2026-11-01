<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class SetProductsCategoryTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private const MUTATION = '
        mutation ($ids: [Int!]!, $categoryId: Int!) {
            SetProductsCategory(ids: $ids, category_id: $categoryId)
        }
    ';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'              => 'Test Admin SetCategory',
            'email'             => 'test_setcat_' . uniqid() . '@example.com',
            'password'          => bcrypt('secret'),
            'email_verified_at' => now(),
        ]);
        $this->user->assignRole(Role::findOrCreate(Role::ADMIN));
    }

    private function makeProduct(): Product
    {
        $shop = Shop::create([
            'title'           => 'Test Shop ' . uniqid(),
            'id_1c'           => 0,
            'default'         => false,
            'slot_max_orders' => 10,
            'slot_time'       => 30,
            'payment_type_id' => 1,
            'is_popular'      => false,
        ]);

        return Product::create([
            'title'                => 'Test Product ' . uniqid(),
            'shop_id'              => $shop->id,
            'unit_type_id'         => 1,
            'status_id'            => 1,
            'fill_percent'         => 0,
            'publish_after_unlock' => false,
        ]);
    }

    /** @test */
    public function test_moves_products_replacing_existing_categories(): void
    {
        $old    = Category::create(['title' => 'Old Cat ' . uniqid()]);
        $target = Category::create(['title' => 'Target Cat ' . uniqid()]);
        $p1     = $this->makeProduct();
        $p2     = $this->makeProduct();
        $p1->categories()->sync([$old->id => ['position' => 0]]);

        $response = $this->actingAs($this->user)->graphQL(
            self::MUTATION,
            ['ids' => [$p1->id, $p2->id], 'categoryId' => $target->id]
        );

        $response->assertJson(['data' => ['SetProductsCategory' => 2]]);
        $this->assertSame([$target->id], $p1->categories()->pluck('categories.id')->all());
        $this->assertSame([$target->id], $p2->categories()->pluck('categories.id')->all());
        $this->assertDatabaseMissing('category_product', ['product_id' => $p1->id, 'category_id' => $old->id]);
    }

    /** @test — NEGATIVE: нелистовая категория отклоняется */
    public function test_non_leaf_category_is_rejected(): void
    {
        $parent = Category::create(['title' => 'Parent ' . uniqid()]);
        Category::create(['title' => 'Child ' . uniqid(), 'parent_id' => $parent->id]);
        $p1 = $this->makeProduct();

        $response = $this->actingAs($this->user)->graphQL(
            self::MUTATION,
            ['ids' => [$p1->id], 'categoryId' => $parent->id]
        );

        $response->assertGraphQLValidationKeys(['category_id']);
        $this->assertDatabaseMissing('category_product', ['product_id' => $p1->id, 'category_id' => $parent->id]);
    }

    /** @test — NEGATIVE: несуществующая категория отклоняется */
    public function test_missing_category_is_rejected(): void
    {
        $p1 = $this->makeProduct();

        $response = $this->actingAs($this->user)->graphQL(
            self::MUTATION,
            ['ids' => [$p1->id], 'categoryId' => 99999999]
        );

        $response->assertGraphQLValidationKeys(['category_id']);
    }

    /** @test — несуществующие товары молча пропускаются */
    public function test_missing_products_are_skipped(): void
    {
        $target = Category::create(['title' => 'Target ' . uniqid()]);
        $p1     = $this->makeProduct();

        $response = $this->actingAs($this->user)->graphQL(
            self::MUTATION,
            ['ids' => [$p1->id, 99999999], 'categoryId' => $target->id]
        );

        $response->assertJson(['data' => ['SetProductsCategory' => 1]]);
        $this->assertSame([$target->id], $p1->categories()->pluck('categories.id')->all());
    }

    /** @test — NEGATIVE: анонимный запрос отклоняется */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $target = Category::create(['title' => 'Target ' . uniqid()]);
        $p1     = $this->makeProduct();

        $response = $this->graphQL(
            self::MUTATION,
            ['ids' => [$p1->id], 'categoryId' => $target->id]
        );

        $this->assertNotEmpty($response->json('errors'));
        $this->assertDatabaseMissing('category_product', ['product_id' => $p1->id, 'category_id' => $target->id]);
    }
}
