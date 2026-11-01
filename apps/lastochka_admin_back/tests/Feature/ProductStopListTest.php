<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Status;
use App\Models\User;
use App\Events\ProductUpdated;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Стоп-листы = представление полей карточки товара (Platrum 12188 / MID-155).
 *
 * «hidden» (не отображать)  = товары магазина со status_id != CODE_ACTIVE;
 * «pickup_only» (не доставлять) = товары магазина с pickup_only = true.
 * Даты попадания в стоп-лист ведут мутаторы модели: hidden_at / pickup_only_at.
 *
 * Мутации/квери защищены @guard @can(model:"Admin",ability:"onlyAdmin")
 * @section(key:"stores"). Тест логинится глобальным админом.
 */
class ProductStopListTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::setValue('role_access_matrix_enabled', '1', 'boolean');

        $adminRole = Role::findOrCreate(Role::ADMIN);
        $adminRole->scope_type = Role::SCOPE_GLOBAL;
        $adminRole->save();

        $this->admin = User::create([
            'name' => 'StopList Admin',
            'phone' => '+7901' . mt_rand(1000000, 9999999),
            'email' => uniqid('stoplist_admin_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->admin->assignRole($adminRole);
        $this->assertTrue($this->admin->fresh()->isGlobalScope());
    }

    private function createShop(): Shop
    {
        return Shop::create(['title' => 'StopList shop ' . uniqid()]);
    }

    /**
     * ВНИМАНИЕ: без явного status_id товар создаётся НЕ активным
     * (Product::boot creating → CODE_NOT_PUBLISHED). «Активный» товар
     * в фикстурах всегда задавать явно: ['status_id' => Status::CODE_ACTIVE].
     */
    private function createProduct(Shop $shop, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'title' => 'Product ' . uniqid(),
            'articul' => 'ART-' . uniqid(),
            'shop_id' => $shop->id,
        ], $attributes));
    }

    private const ADD_MUTATION = '
        mutation ProductStopListAdd($product_id: Int!, $type: String!) {
            ProductStopListAdd(product_id: $product_id, type: $type) {
                code
                message
            }
        }
    ';

    private const REMOVE_MUTATION = '
        mutation ProductStopListRemove($product_id: Int!, $type: String!) {
            ProductStopListRemove(product_id: $product_id, type: $type) {
                code
                message
            }
        }
    ';

    private const LIST_QUERY = '
        query ShopStopList($shop_id: Int!, $type: String!) {
            shop_stop_list_products(shop_id: $shop_id, type: $type, first: 50) {
                data {
                    id
                    articul
                    title
                    categories { title }
                    hidden_at
                    pickup_only_at
                }
                paginatorInfo { total }
            }
        }
    ';

    // ==================== Схема ====================

    public function test_products_table_has_unified_date_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'hidden_at'));
        $this->assertTrue(Schema::hasColumn('products', 'pickup_only_at'));
    }

    public function test_products_table_has_no_stop_list_flag_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('products', 'stop_list_hidden'));
        $this->assertFalse(Schema::hasColumn('products', 'stop_list_hidden_at'));
        $this->assertFalse(Schema::hasColumn('products', 'stop_list_pickup_only'));
        $this->assertFalse(Schema::hasColumn('products', 'stop_list_pickup_only_at'));
    }

    // ==================== Мутаторы дат ====================

    public function test_hiding_product_sets_hidden_at(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);
        $this->assertNull($product->fresh()->hidden_at);

        $product->update(['status_id' => Status::CODE_NOT_PUBLISHED]);

        $this->assertNotNull($product->fresh()->hidden_at);
    }

    public function test_publishing_product_clears_hidden_at(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);
        $this->assertNotNull($product->fresh()->hidden_at);

        $product->update(['status_id' => Status::CODE_ACTIVE]);

        $this->assertNull($product->fresh()->hidden_at);
    }

    public function test_product_created_inactive_by_default_gets_hidden_at(): void
    {
        $shop = $this->createShop();
        // Без явного status_id: creating-хук ставит CODE_NOT_PUBLISHED →
        // мутатор обязан проставить hidden_at (дата = момент создания).
        $product = $this->createProduct($shop);

        $fresh = $product->fresh();
        $this->assertSame(Status::CODE_NOT_PUBLISHED, (int) $fresh->status_id);
        $this->assertNotNull($fresh->hidden_at);
    }

    public function test_resaving_same_status_keeps_hidden_at(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        $old = now()->subDay()->startOfSecond();
        DB::table('products')->where('id', $product->id)->update(['hidden_at' => $old]);

        // Повторная запись ТОГО ЖЕ статуса не должна сдвигать дату.
        $product->fresh()->update(['status_id' => Status::CODE_NOT_PUBLISHED]);

        $this->assertTrue($old->equalTo($product->fresh()->hidden_at));
    }

    public function test_pickup_only_toggle_maintains_pickup_only_at(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);
        $this->assertNull($product->fresh()->pickup_only_at);

        $product->update(['pickup_only' => true]);
        $this->assertNotNull($product->fresh()->pickup_only_at);

        $product->fresh()->update(['pickup_only' => false]);
        $this->assertNull($product->fresh()->pickup_only_at);
    }

    public function test_resaving_same_pickup_only_keeps_date(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'pickup_only' => true,
        ]);

        $old = now()->subDay()->startOfSecond();
        DB::table('products')->where('id', $product->id)->update(['pickup_only_at' => $old]);

        $product->fresh()->update(['pickup_only' => true]);

        $this->assertTrue($old->equalTo($product->fresh()->pickup_only_at));
    }

    public function test_mutators_work_under_without_events(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);

        // Консьюмеры RabbitMQ сохраняют через withoutEvents() — мутаторы
        // (в отличие от observer'ов) обязаны отработать и там.
        Product::withoutEvents(function () use ($product) {
            $product->update([
                'status_id' => Status::CODE_NOT_PUBLISHED,
                'pickup_only' => true,
            ]);
        });

        $fresh = $product->fresh();
        $this->assertNotNull($fresh->hidden_at);
        $this->assertNotNull($fresh->pickup_only_at);
    }

    // ==================== Query shop_stop_list_products ====================

    public function test_hidden_list_returns_inactive_products_of_shop(): void
    {
        $shop = $this->createShop();
        $otherShop = $this->createShop();

        $category = Category::create(['title' => 'Cat ' . uniqid(), 'link' => 'cat-' . uniqid()]);

        // Неактивный товар нужного магазина — должен попасть.
        $hidden = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);
        $hidden->categories()->attach($category->id, ['position' => 0]);

        // Активный — не должен.
        $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);

        // pickup_only, но активный — не должен попасть в hidden-выдачу.
        $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'pickup_only' => true,
        ]);

        // Неактивный, но чужой магазин — не должен.
        $this->createProduct($otherShop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        // Неактивная ВАРИАЦИЯ configurable-товара — не самостоятельная позиция.
        $parent = $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'is_configurable' => true,
        ]);
        $this->createProduct($shop, [
            'status_id' => Status::CODE_NOT_PUBLISHED,
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->graphQL(self::LIST_QUERY, ['shop_id' => $shop->id, 'type' => 'hidden']);

        $response->assertJsonPath('data.shop_stop_list_products.paginatorInfo.total', 1);
        $response->assertJsonPath('data.shop_stop_list_products.data.0.id', $hidden->id);
        $response->assertJsonPath('data.shop_stop_list_products.data.0.categories.0.title', $category->title);
        $this->assertNotNull($response->json('data.shop_stop_list_products.data.0.hidden_at'));
    }

    public function test_pickup_only_list_returns_pickup_only_products(): void
    {
        $shop = $this->createShop();

        $pickup = $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'pickup_only' => true,
        ]);

        // Неактивный, но не pickup_only — не должен попасть.
        $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        $response = $this->actingAs($this->admin)
            ->graphQL(self::LIST_QUERY, ['shop_id' => $shop->id, 'type' => 'pickup_only']);

        $response->assertJsonPath('data.shop_stop_list_products.paginatorInfo.total', 1);
        $response->assertJsonPath('data.shop_stop_list_products.data.0.id', $pickup->id);
        $this->assertNotNull($response->json('data.shop_stop_list_products.data.0.pickup_only_at'));
    }

    public function test_hidden_list_sorted_by_hidden_at_desc(): void
    {
        $shop = $this->createShop();

        $older = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);
        DB::table('products')->where('id', $older->id)
            ->update(['hidden_at' => now()->subDays(2)]);

        $newer = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        $response = $this->actingAs($this->admin)
            ->graphQL(self::LIST_QUERY, ['shop_id' => $shop->id, 'type' => 'hidden']);

        $response->assertJsonPath('data.shop_stop_list_products.data.0.id', $newer->id);
        $response->assertJsonPath('data.shop_stop_list_products.data.1.id', $older->id);
    }

    public function test_query_rejects_invalid_type(): void
    {
        $shop = $this->createShop();

        $response = $this->actingAs($this->admin)
            ->graphQL(self::LIST_QUERY, ['shop_id' => $shop->id, 'type' => 'bogus']);

        $response->assertJsonPath('errors.0.extensions.category', 'validation');
    }

    // ==================== Мутации Add/Remove ====================

    public function test_add_hidden_deactivates_product_like_card_toggle(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'publish_after_unlock' => 1,
        ]);

        $this->actingAs($this->admin)
            ->graphQL(self::ADD_MUTATION, ['product_id' => $product->id, 'type' => 'hidden'])
            ->assertJsonPath('data.ProductStopListAdd.code', 200);

        $fresh = $product->fresh();
        $this->assertSame(Status::CODE_NOT_PUBLISHED, (int) $fresh->status_id);
        $this->assertSame(0, (int) $fresh->publish_after_unlock);
        $this->assertNotNull($fresh->hidden_at);

        // Паритет с тогглером карточки: смена статуса пишется в историю товара.
        $this->assertTrue(
            ProductHistory::query()
                ->where('product_id', $product->id)
                ->where('action', ProductHistory::ACTION_STATUS_CHANGED)
                ->exists()
        );
    }

    public function test_add_hidden_on_already_inactive_product_is_noop(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        $old = now()->subDay()->startOfSecond();
        DB::table('products')->where('id', $product->id)->update(['hidden_at' => $old]);

        $this->actingAs($this->admin)
            ->graphQL(self::ADD_MUTATION, ['product_id' => $product->id, 'type' => 'hidden'])
            ->assertJsonPath('data.ProductStopListAdd.code', 200);

        $fresh = $product->fresh();
        // Дата не сдвинулась, дублирующая запись истории не появилась.
        $this->assertTrue($old->equalTo($fresh->hidden_at));
        $this->assertSame(
            0,
            ProductHistory::query()
                ->where('product_id', $product->id)
                ->where('action', ProductHistory::ACTION_STATUS_CHANGED)
                ->count()
        );
    }

    public function test_remove_hidden_publishes_product(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        $this->actingAs($this->admin)
            ->graphQL(self::REMOVE_MUTATION, ['product_id' => $product->id, 'type' => 'hidden'])
            ->assertJsonPath('data.ProductStopListRemove.code', 200);

        $fresh = $product->fresh();
        $this->assertSame(Status::CODE_ACTIVE, (int) $fresh->status_id);
        $this->assertSame(1, (int) $fresh->publish_after_unlock);
        $this->assertNull($fresh->hidden_at);
        $this->assertTrue(
            ProductHistory::query()
                ->where('product_id', $product->id)
                ->where('action', ProductHistory::ACTION_STATUS_CHANGED)
                ->exists()
        );
    }

    public function test_add_and_remove_pickup_only_toggle_card_field(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);

        $this->actingAs($this->admin)
            ->graphQL(self::ADD_MUTATION, ['product_id' => $product->id, 'type' => 'pickup_only'])
            ->assertJsonPath('data.ProductStopListAdd.code', 200);

        $fresh = $product->fresh();
        $this->assertTrue((bool) $fresh->pickup_only);
        $this->assertNotNull($fresh->pickup_only_at);
        // Статус не задет.
        $this->assertSame(Status::CODE_ACTIVE, (int) $fresh->status_id);

        $this->actingAs($this->admin)
            ->graphQL(self::REMOVE_MUTATION, ['product_id' => $product->id, 'type' => 'pickup_only'])
            ->assertJsonPath('data.ProductStopListRemove.code', 200);

        $fresh = $product->fresh();
        $this->assertFalse((bool) $fresh->pickup_only);
        $this->assertNull($fresh->pickup_only_at);
    }

    public function test_add_dispatches_product_updated_event(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);

        $this->actingAs($this->admin)
            ->graphQL(self::ADD_MUTATION, ['product_id' => $product->id, 'type' => 'hidden'])
            ->assertJsonPath('data.ProductStopListAdd.code', 200);

        // products.updated не слушается пакетом — событие обязано уйти вручную,
        // иначе деактивация не доедет до storefront через outbox.
        Event::assertDispatched(
            ProductUpdated::class,
            fn (ProductUpdated $e) => $e->product->id === $product->id
        );
    }

    public function test_remove_dispatches_product_updated_event(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->createShop();
        $product = $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'pickup_only' => true,
        ]);

        $this->actingAs($this->admin)
            ->graphQL(self::REMOVE_MUTATION, ['product_id' => $product->id, 'type' => 'pickup_only'])
            ->assertJsonPath('data.ProductStopListRemove.code', 200);

        Event::assertDispatched(
            ProductUpdated::class,
            fn (ProductUpdated $e) => $e->product->id === $product->id
        );
    }

    public function test_add_rejects_invalid_type(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);

        $response = $this->actingAs($this->admin)
            ->graphQL(self::ADD_MUTATION, ['product_id' => $product->id, 'type' => 'bogus']);

        $response->assertJsonPath('errors.0.extensions.category', 'validation');
        $this->assertSame(Status::CODE_ACTIVE, (int) $product->fresh()->status_id);
    }

    // ==================== Событие product.updated ====================

    public function test_event_payload_reflects_card_fields_without_stop_list_keys(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, [
            'status_id' => Status::CODE_NOT_PUBLISHED,
            'pickup_only' => true,
        ]);

        $payload = (new ProductEventTransformer())->toEvent($product->fresh());

        $this->assertArrayNotHasKey('stop_list_hidden', $payload);
        $this->assertArrayNotHasKey('stop_list_pickup_only', $payload);
        // Объединённая семантика уходит существующими ключами.
        $this->assertFalse($payload['active']);
        $this->assertTrue($payload['pickup_only']);
    }
}
