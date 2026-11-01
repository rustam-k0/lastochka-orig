<?php

namespace Tests\Feature\Supplement;

use App\Models\Product;
use App\Models\Shop;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class CatalogSupplementConfigTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

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
            'title'               => 'Test Product ' . uniqid(),
            'shop_id'             => $shop->id,
            'unit_type_id'        => 1,
            'status_id'           => 1,
            'fill_percent'        => 0,
            'publish_after_unlock' => false,
        ]);
    }

    /** @test */
    public function test_supplement_value_stores_catalog_fields(): void
    {
        $sauce = $this->makeProduct();
        $main  = $this->makeProduct();
        $sup = Supplement::create(['product_id' => $main->id, 'title' => 'Соус', 'type' => 'many', 'is_required' => false]);
        $val = SupplementValue::create([
            'supplement_id' => $sup->id, 'title' => 'Сырный',
            'product_id' => $sauce->id, 'max_quantity' => 3, 'price' => null, 'position' => 1,
        ]);
        $this->assertSame($sauce->id, $val->fresh()->product_id);
        $this->assertNull($val->fresh()->track_stock);
    }

    /** @test */
    public function test_product_update_catalog_supplement_price_null_with_product_id(): void
    {
        $user = User::create([
            'name'              => 'Test Admin',
            'email'             => 'test_admin_' . uniqid() . '@example.com',
            'password'          => bcrypt('secret'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole(\App\Models\Role::findOrCreate(\App\Models\Role::ADMIN));

        $sauce  = $this->makeProduct();
        $main   = $this->makeProduct();

        $response = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $shopId: Int!, $sauceId: Int!) {
                ProductUpdate(id: $id, price: 100, shop_id: $shopId, supplements: [{
                    title: "Соус", type: "many", is_required: false,
                    values: [{ title: "Сырный", product_id: $sauceId, max_quantity: 3 }]
                }]) { code message }
            }
        ', ['id' => $main->id, 'shopId' => $main->shop_id, 'sauceId' => $sauce->id]);

        $response->assertJsonMissingValidationErrors();

        $this->assertDatabaseHas('supplement_values', [
            'title'      => 'Сырный',
            'product_id' => $sauce->id,
        ]);

        $this->assertNull(SupplementValue::where('title', 'Сырный')->where('product_id', $sauce->id)->first()->price);
    }

    /** @test */
    public function test_product_update_virtual_supplement_without_price_fails_validation(): void
    {
        $user = User::create([
            'name'              => 'Test Admin',
            'email'             => 'test_admin_' . uniqid() . '@example.com',
            'password'          => bcrypt('secret'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole(\App\Models\Role::findOrCreate(\App\Models\Role::ADMIN));

        $main = $this->makeProduct();

        $response = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $shopId: Int!) {
                ProductUpdate(id: $id, price: 100, shop_id: $shopId, supplements: [{
                    title: "Соус", type: "many", is_required: false,
                    values: [{ title: "Виртуальная добавка без цены", max_quantity: 1 }]
                }]) { code message }
            }
        ', ['id' => $main->id, 'shopId' => $main->shop_id]);

        $response->assertGraphQLValidationKeys(['supplements.0.values.0.price']);
    }

    /** @test */
    public function test_product_update_saves_catalog_supplement(): void
    {
        $user = User::create([
            'name'              => 'Test Admin',
            'email'             => 'test_admin_' . uniqid() . '@example.com',
            'password'          => bcrypt('secret'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole(\App\Models\Role::findOrCreate(\App\Models\Role::ADMIN));

        $sauce = $this->makeProduct();
        $main  = $this->makeProduct();

        $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $shopId: Int!, $sauceId: Int!) {
                ProductUpdate(id: $id, price: 100, shop_id: $shopId, supplements: [{
                    title: "Соус", type: "many", is_required: false,
                    values: [{ title: "Сырный", price: 0, product_id: $sauceId, max_quantity: 3 }]
                }]) { code message }
            }
        ', ['id' => $main->id, 'shopId' => $main->shop_id, 'sauceId' => $sauce->id]);

        $this->assertDatabaseHas('supplement_values', [
            'title'       => 'Сырный',
            'product_id'  => $sauce->id,
            'max_quantity' => 3,
        ]);
    }

    /** @test */
    public function test_product_event_includes_catalog_supplement_ref(): void
    {
        $sauce = $this->makeProduct();

        // Удаляем автоматически созданную metadata (ModelEventObserver при Product::create)
        // и вставляем свою, сэмулировав товар пришедший из внешнего источника (1С).
        EventSyncMetadata::where([
            'entity_type' => 'products',
            'entity_id'   => $sauce->id,
        ])->delete();

        EventSyncMetadata::create([
            'entity_type'          => 'products',
            'entity_id'            => $sauce->id,
            'source'               => 'test-source',
            'external_id'          => 'sauce-guid',
            'shop_code'            => 'default',
            'last_event_id'        => \Illuminate\Support\Str::uuid()->toString(),
            'last_event_timestamp' => now(),
        ]);

        $main = $this->makeProduct();
        $sup  = Supplement::create(['product_id' => $main->id, 'title' => 'Соус', 'type' => 'many', 'is_required' => false]);
        SupplementValue::create([
            'supplement_id' => $sup->id,
            'title'         => 'Сырный',
            'product_id'    => $sauce->id,
            'max_quantity'  => 3,
            'price'         => null,
        ]);

        $main->load('supplements.values');
        $payload = app(\App\Transformers\ProductEventTransformer::class)->toEvent($main);

        $value = $payload['supplements'][0]['values'][0];
        $this->assertSame('sauce-guid', $value['product_external_id']);
        $this->assertSame(3, $value['max_quantity']);
        $this->assertNull($value['price']);
    }
}
