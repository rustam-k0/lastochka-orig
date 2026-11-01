<?php

namespace Tests\Feature;

use App\Events\ShopCategoryDetached;
use App\GraphQL\Mutations\Shop\ShopCategorySectionDelete;
use App\Models\AdminSetting;
use App\Models\Category;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Models\ShopZone;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Services\ShopService;
use App\Transformers\ShopEventTransformer;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * T5 — раздел «Магазины» вкладками (admin_back):
 *  1. ShopUpdate.status_id: админ активирует/снимает магазин с публикации (1↔3),
 *     статус 2 отклоняется, партнёр (shop-scope) НЕ может менять статус.
 *  2. ShopCategorySectionDelete: удаление строки pivot shops_categories,
 *     идемпотентность, публикация detach-события в outbox для storefront.
 *  3. ShopEventTransformer::getPrimaryDeliveryZone — первая АКТИВНАЯ зона
 *     (деактивированная не должна утаскивать свои цены в storefront).
 *
 * Матрица доступов включена (role_access_matrix_enabled='1') — штатное состояние
 * shared mendev_test. Админ — глобальный (scope_type=global), проходит onlyAdmin.
 */
class ShopStatusSectionTest extends TestCase
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
            'name' => 'Shop Status Admin',
            'phone' => '+7902' . mt_rand(1000000, 9999999),
            'email' => uniqid('shopstatus_admin_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->admin->assignRole($adminRole);
        $this->assertTrue($this->admin->fresh()->isGlobalScope());
    }

    private function createShop(array $attrs = []): Shop
    {
        return Shop::create(array_merge([
            'title' => 'Status shop ' . uniqid(),
            'status_id' => Status::CODE_ACTIVE,
        ], $attrs));
    }

    /** Партнёр — пользователь с ролью shop-scope (не global, не байпасит). */
    private function createShopUser(): User
    {
        $role = Role::findOrCreate(Role::SHOP);
        if ($role->scope_type !== Role::SCOPE_SHOP) {
            $role->update(['scope_type' => Role::SCOPE_SHOP]);
        }

        $user = User::create([
            'name' => 'Partner ' . uniqid(),
            'phone' => '+7903' . mt_rand(1000000, 9999999),
            'email' => uniqid('partner_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function createZone(Shop $shop, array $attrs = []): ShopZone
    {
        return ShopZone::create(array_merge([
            'shop_id' => $shop->id,
            'name' => 'Зона ' . uniqid(),
            'delivery_price' => 200,
            'min_order_price' => 600,
            'delivery_type' => 'own',
            'is_active' => true,
        ], $attrs));
    }

    // slot_time/slot_max_orders/type_id обязательны для глобального админа
    // (adminRequiredRule). pickup_wait_* передаём равными DB-дефолтам (1/3/1) —
    // колонки NOT NULL, а fillInfo пишет `$args[...] ?? null` без fallback.
    private const UPDATE_MUTATION = '
        mutation ShopUpdate(
            $id: Int!
            $title: String!
            $slot_time: Int!
            $slot_max_orders: Int!
            $type_id: Int!
            $pickup_wait_perishable_hours: Int!
            $pickup_wait_regular_hours: Int!
            $pickup_grace_period_hours: Int!
            $status_id: Int
        ) {
            ShopUpdate(
                id: $id
                title: $title
                slot_time: $slot_time
                slot_max_orders: $slot_max_orders
                type_id: $type_id
                pickup_wait_perishable_hours: $pickup_wait_perishable_hours
                pickup_wait_regular_hours: $pickup_wait_regular_hours
                pickup_grace_period_hours: $pickup_grace_period_hours
                status_id: $status_id
            ) {
                code
                message
            }
        }
    ';

    private function baseVariables(Shop $shop): array
    {
        return [
            'id' => $shop->id,
            'title' => $shop->title,
            'slot_time' => 60,
            'slot_max_orders' => 10,
            'type_id' => Type::SHOP_TYPE_STORE,
            'pickup_wait_perishable_hours' => 1,
            'pickup_wait_regular_hours' => 3,
            'pickup_grace_period_hours' => 1,
        ];
    }

    // ----------------------------------------------------------------------
    //  ShopUpdate.status_id
    // ----------------------------------------------------------------------

    /** @test */
    public function admin_can_deactivate_shop_via_status_id()
    {
        $shop = $this->createShop(['status_id' => Status::CODE_ACTIVE]);

        $this->actingAs($this->admin)
            ->graphQL(self::UPDATE_MUTATION, array_merge(
                $this->baseVariables($shop),
                ['status_id' => Status::CODE_NOT_PUBLISHED]
            ))
            ->assertJson(['data' => ['ShopUpdate' => ['code' => 200]]]);

        $this->assertSame(Status::CODE_NOT_PUBLISHED, (int) $shop->fresh()->status_id);
    }

    /** @test */
    public function admin_can_reactivate_shop_via_status_id()
    {
        $shop = $this->createShop(['status_id' => Status::CODE_NOT_PUBLISHED]);

        $this->actingAs($this->admin)
            ->graphQL(self::UPDATE_MUTATION, array_merge(
                $this->baseVariables($shop),
                ['status_id' => Status::CODE_ACTIVE]
            ))
            ->assertJson(['data' => ['ShopUpdate' => ['code' => 200]]]);

        $this->assertSame(Status::CODE_ACTIVE, (int) $shop->fresh()->status_id);
    }

    /** @test */
    public function status_id_rejects_invalid_value()
    {
        $shop = $this->createShop(['status_id' => Status::CODE_ACTIVE]);

        $response = $this->actingAs($this->admin)
            ->graphQL(self::UPDATE_MUTATION, array_merge(
                $this->baseVariables($shop),
                ['status_id' => 2] // не входит в [1,3]
            ));

        $response->assertGraphQLValidationKeys(['status_id']);
        // Статус не должен измениться при отказе валидации.
        $this->assertSame(Status::CODE_ACTIVE, (int) $shop->fresh()->status_id);
    }

    /** @test */
    public function status_id_is_preserved_when_omitted()
    {
        $shop = $this->createShop(['status_id' => Status::CODE_NOT_PUBLISHED]);

        // status_id не передаём (переменная = null) — паттерн "?? $shop->status_id".
        $this->actingAs($this->admin)
            ->graphQL(self::UPDATE_MUTATION, $this->baseVariables($shop))
            ->assertJson(['data' => ['ShopUpdate' => ['code' => 200]]]);

        $this->assertSame(Status::CODE_NOT_PUBLISHED, (int) $shop->fresh()->status_id);
    }

    /** @test */
    public function partner_cannot_change_status_id()
    {
        $partner = $this->createShopUser();
        $this->assertTrue($partner->isShopScope());

        $shop = $this->createShop(['status_id' => Status::CODE_ACTIVE]);

        // fillInfo для shop-scope применяет только базовый блок и выходит —
        // status_id (админский блок) партнёру недоступен.
        $this->actingAs($partner);
        (new ShopService())->fillInfo($shop, [
            'title' => 'Partner renamed',
            'status_id' => Status::CODE_NOT_PUBLISHED, // попытка снять с публикации
        ]);

        $fresh = $shop->fresh();
        $this->assertSame(
            Status::CODE_ACTIVE,
            (int) $fresh->status_id,
            'Партнёр не должен менять статус магазина'
        );
        $this->assertSame(
            'Partner renamed',
            $fresh->title,
            'Базовый блок партнёра (title) должен примениться'
        );
    }

    // ----------------------------------------------------------------------
    //  ShopCategorySectionDelete
    // ----------------------------------------------------------------------

    /** @test */
    public function section_delete_removes_pivot_row()
    {
        $shop = $this->createShop();
        $category = Category::create(['title' => 'Section ' . uniqid()]);
        ShopCategory::create(['shop_id' => $shop->id, 'category_id' => $category->id]);

        $response = (new ShopCategorySectionDelete())(null, [
            'shop_id' => $shop->id,
            'category_id' => $category->id,
        ]);

        $this->assertSame(200, $response['code']);
        $this->assertNull(
            ShopCategory::where('shop_id', $shop->id)->where('category_id', $category->id)->first(),
            'Строка pivot shops_categories должна быть удалена'
        );
    }

    /** @test */
    public function section_delete_is_idempotent()
    {
        $shop = $this->createShop();
        $category = Category::create(['title' => 'Section ' . uniqid()]);
        ShopCategory::create(['shop_id' => $shop->id, 'category_id' => $category->id]);

        (new ShopCategorySectionDelete())(null, ['shop_id' => $shop->id, 'category_id' => $category->id]);
        $second = (new ShopCategorySectionDelete())(null, ['shop_id' => $shop->id, 'category_id' => $category->id]);

        $this->assertSame(200, $second['code'], 'Повторное удаление — 200 no-op');
        $this->assertNull(
            ShopCategory::where('shop_id', $shop->id)->where('category_id', $category->id)->first()
        );
    }

    /** @test */
    public function section_delete_publishes_detach_event_to_outbox()
    {
        $shop = $this->createShop();
        $category = Category::create(['title' => 'Section ' . uniqid()]);
        ShopCategory::create(['shop_id' => $shop->id, 'category_id' => $category->id]);

        (new ShopCategorySectionDelete())(null, [
            'shop_id' => $shop->id,
            'category_id' => $category->id,
        ]);

        $outbox = EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $category->id)
            ->where('event_type', 'shop_detached')
            ->first();

        $this->assertNotNull($outbox, 'shop_detached outbox row должен появиться для отвязанной категории');
        $this->assertSame('category.shop_detached', $outbox->routing_key);
        $this->assertSame($shop->code, $outbox->payload['shop_code']);
        $this->assertSame($category->title, $outbox->payload['title']);
    }

    /** @test */
    public function section_delete_does_not_dispatch_event_when_not_linked()
    {
        Event::fake([ShopCategoryDetached::class]);

        $shop = $this->createShop();
        $category = Category::create(['title' => 'Unlinked ' . uniqid()]); // НЕ привязана

        $response = (new ShopCategorySectionDelete())(null, [
            'shop_id' => $shop->id,
            'category_id' => $category->id,
        ]);

        $this->assertSame(200, $response['code']);
        Event::assertNotDispatched(ShopCategoryDetached::class);
    }

    /** @test */
    public function section_delete_works_end_to_end_via_graphql()
    {
        $shop = $this->createShop();
        $category = Category::create(['title' => 'Section ' . uniqid()]);
        ShopCategory::create(['shop_id' => $shop->id, 'category_id' => $category->id]);

        $this->actingAs($this->admin)
            ->graphQL('
                mutation ($shop_id: Int!, $category_id: Int!) {
                    ShopCategorySectionDelete(shop_id: $shop_id, category_id: $category_id) {
                        code
                        message
                    }
                }
            ', ['shop_id' => $shop->id, 'category_id' => $category->id])
            ->assertJson(['data' => ['ShopCategorySectionDelete' => ['code' => 200]]]);

        $this->assertNull(
            ShopCategory::where('shop_id', $shop->id)->where('category_id', $category->id)->first()
        );
    }

    // ----------------------------------------------------------------------
    //  ShopEventTransformer::getPrimaryDeliveryZone
    // ----------------------------------------------------------------------

    /** @test */
    public function primary_zone_picks_first_active_skipping_inactive()
    {
        $shop = $this->createShop();

        // Неактивная зона создаётся ПЕРВОЙ (меньший id) — должна быть пропущена.
        $this->createZone($shop, [
            'name' => 'Inactive',
            'delivery_price' => 100,
            'min_order_price' => 500,
            'is_active' => false,
        ]);
        $this->createZone($shop, [
            'name' => 'Active',
            'delivery_price' => 200,
            'min_order_price' => 600,
            'free_delivery_from' => 3000,
            'is_active' => true,
        ]);

        $payload = (new ShopEventTransformer())->toEvent($shop->fresh());

        $this->assertSame(200, (int) $payload['delivery_price']);
        $this->assertSame(600, (int) $payload['min_order_price']);
        $this->assertSame(3000, (int) $payload['free_delivery_from']);
    }

    /** @test */
    public function primary_zone_is_null_when_all_zones_inactive()
    {
        $shop = $this->createShop();

        $this->createZone($shop, ['delivery_price' => 100, 'is_active' => false]);
        $this->createZone($shop, ['delivery_price' => 200, 'is_active' => false]);

        $payload = (new ShopEventTransformer())->toEvent($shop->fresh());

        $this->assertNull($payload['delivery_price'], 'Нет активных зон → цены как при отсутствии зон');
        $this->assertNull($payload['min_order_price']);
        $this->assertNull($payload['free_delivery_from']);
        $this->assertSame('own', $payload['delivery_type']); // дефолт при отсутствии зоны
    }

    /** @test */
    public function primary_zone_picks_first_among_multiple_active()
    {
        $shop = $this->createShop();

        $this->createZone($shop, ['name' => 'A', 'delivery_price' => 300, 'is_active' => true]);
        $this->createZone($shop, ['name' => 'B', 'delivery_price' => 400, 'is_active' => true]);

        $payload = (new ShopEventTransformer())->toEvent($shop->fresh());

        $this->assertSame(300, (int) $payload['delivery_price']);
    }
}
