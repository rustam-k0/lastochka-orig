<?php

namespace Tests\Feature\Supplement;

use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class BulkAssignSupplementsTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'              => 'Test Admin BulkAssign',
            'email'             => 'test_bulk_' . uniqid() . '@example.com',
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
    public function test_bulk_assign_creates_supplement_for_each_product(): void
    {
        $sauce = $this->makeProduct();
        $p1    = $this->makeProduct();
        $p2    = $this->makeProduct();

        $response = $this->actingAs($this->user)->graphQL('
            mutation ($ids: [Int!]!, $sauceId: Int!) {
                BulkAssignSupplements(product_ids: $ids, supplement: {
                    title: "Соусы", type: "many", is_required: false,
                    values: [{ title: "Сырный", product_id: $sauceId, max_quantity: 3 }]
                }) { code message }
            }
        ', ['ids' => [$p1->id, $p2->id], 'sauceId' => $sauce->id]);

        $response->assertJsonMissingValidationErrors();
        $response->assertJson(['data' => ['BulkAssignSupplements' => ['code' => 200]]]);

        $this->assertDatabaseHas('supplements', ['product_id' => $p1->id, 'title' => 'Соусы']);
        $this->assertDatabaseHas('supplements', ['product_id' => $p2->id, 'title' => 'Соусы']);
        $this->assertSame(2, SupplementValue::where('title', 'Сырный')->count());
    }

    /** @test */
    public function test_bulk_assign_is_idempotent(): void
    {
        $sauce = $this->makeProduct();
        $p1    = $this->makeProduct();

        $mutation = '
            mutation ($ids: [Int!]!, $sauceId: Int!) {
                BulkAssignSupplements(product_ids: $ids, supplement: {
                    title: "Соусы", type: "many", is_required: false,
                    values: [{ title: "Сырный", product_id: $sauceId, max_quantity: 3 }]
                }) { code message }
            }
        ';
        $vars = ['ids' => [$p1->id], 'sauceId' => $sauce->id];

        // Первый вызов
        $this->actingAs($this->user)->graphQL($mutation, $vars);
        // Второй вызов — не должен плодить дубли
        $this->actingAs($this->user)->graphQL($mutation, $vars);

        $this->assertSame(1, Supplement::where('product_id', $p1->id)->where('title', 'Соусы')->count());
        $this->assertSame(1, SupplementValue::where('title', 'Сырный')->count());
    }

    /** @test — NEGATIVE: анонимный запрос должен получить ошибку авторизации */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $p1 = $this->makeProduct();

        $response = $this->graphQL('
            mutation ($ids: [Int!]!) {
                BulkAssignSupplements(product_ids: $ids, supplement: {
                    title: "Соусы", type: "many", is_required: false,
                    values: [{ title: "Сырный", price: 50 }]
                }) { code message }
            }
        ', ['ids' => [$p1->id]]);

        // Lighthouse + @can без guard возвращает authorization error (не Unauthenticated)
        $response->assertGraphQLErrorMessage('This action is unauthorized.');

        // Добавка НЕ создалась
        $this->assertDatabaseMissing('supplements', ['product_id' => $p1->id, 'title' => 'Соусы']);
    }

    /** @test — NEGATIVE: виртуальная добавка (без product_id) без price → ошибка валидации */
    public function test_virtual_value_without_price_fails_validation(): void
    {
        $p1 = $this->makeProduct();

        $response = $this->actingAs($this->user)->graphQL('
            mutation ($ids: [Int!]!) {
                BulkAssignSupplements(product_ids: $ids, supplement: {
                    title: "Виртуальная", type: "one", is_required: false,
                    values: [{ title: "Без цены" }]
                }) { code message }
            }
        ', ['ids' => [$p1->id]]);

        // Ожидаем ошибку валидации — price обязательна если нет product_id
        $response->assertGraphQLValidationKeys(['supplement.values.0.price']);

        $this->assertDatabaseMissing('supplements', ['product_id' => $p1->id, 'title' => 'Виртуальная']);
    }

    /** @test — POSITIVE: товарная добавка (с product_id) без price → проходит */
    public function test_product_value_without_price_passes_validation(): void
    {
        $sauce = $this->makeProduct();
        $p1    = $this->makeProduct();

        $response = $this->actingAs($this->user)->graphQL('
            mutation ($ids: [Int!]!, $sauceId: Int!) {
                BulkAssignSupplements(product_ids: $ids, supplement: {
                    title: "Товарная", type: "one", is_required: false,
                    values: [{ title: "Продуктовый соус", product_id: $sauceId }]
                }) { code message }
            }
        ', ['ids' => [$p1->id], 'sauceId' => $sauce->id]);

        $response->assertJsonMissingValidationErrors();
        $response->assertJson(['data' => ['BulkAssignSupplements' => ['code' => 200]]]);

        $this->assertDatabaseHas('supplements', ['product_id' => $p1->id, 'title' => 'Товарная']);
    }
}
