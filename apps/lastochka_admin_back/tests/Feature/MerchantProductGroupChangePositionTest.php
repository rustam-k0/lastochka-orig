<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MerchantProductGroup;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MerchantProductGroupChangePositionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    private User $user;
    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        // The rabbitmq-events package uses a static flag $observersRegistered that
        // prevents re-registering Eloquent observers between tests. After the first
        // test in the suite, the next test boots a fresh app with a fresh event
        // dispatcher but the package's `boot()` short-circuits and observers are
        // never wired to the new dispatcher. Reset the flag so each test re-registers.
        $reflection = new \ReflectionClass(\Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class);
        if ($reflection->hasProperty('observersRegistered')) {
            $prop = $reflection->getProperty('observersRegistered');
            $prop->setAccessible(true);
            $prop->setValue(null, false);
        }

        // Re-run the provider boot to actually attach observers to the current app.
        $provider = new \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider($this->app);
        $provider->boot();

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
    }

    private function createGroup(int $sortOrder): MerchantProductGroup
    {
        return MerchantProductGroup::create([
            'shop_id' => $this->shop->id,
            'title' => 'Group ' . uniqid(),
            'show_on_main' => false,
            'show_on_merchant_page' => true,
            'show_in_merchant_catalog' => false,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }

    private function makeShopUser(Shop $shop): User
    {
        $user = User::create([
            'name' => 'Shop User ' . uniqid(),
            'email' => uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->assignRole(Role::SHOP);

        $user->shops()->create(['shop_id' => $shop->id]);

        return $user;
    }

    /** @test */
    public function it_updates_sort_order_for_multiple_groups_in_one_call(): void
    {
        $g1 = $this->createGroup(0);
        $g2 = $this->createGroup(1);
        $g3 = $this->createGroup(2);

        $mutation = '
            mutation Reorder($items: [MerchantProductGroupPositionInput!]!) {
                MerchantProductGroupChangePosition(items: $items) {
                    code
                    message
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'items' => [
                    ['id' => $g1->id, 'sort_order' => 2],
                    ['id' => $g2->id, 'sort_order' => 0],
                    ['id' => $g3->id, 'sort_order' => 1],
                ],
            ]);

        $response->assertJsonPath('data.MerchantProductGroupChangePosition.code', 200);

        $this->assertSame(2, $g1->fresh()->sort_order);
        $this->assertSame(0, $g2->fresh()->sort_order);
        $this->assertSame(1, $g3->fresh()->sort_order);
    }

    /** @test */
    public function it_publishes_outbox_event_per_updated_group(): void
    {
        $g = $this->createGroup(0);

        $beforeCount = DB::table('event_outbox')
            ->where('routing_key', 'merchant_product_group.updated')
            ->count();

        $mutation = '
            mutation Reorder($items: [MerchantProductGroupPositionInput!]!) {
                MerchantProductGroupChangePosition(items: $items) {
                    code
                }
            }
        ';

        $this->actingAs($this->user)->graphQL($mutation, [
            'items' => [['id' => $g->id, 'sort_order' => 5]],
        ]);

        $afterCount = DB::table('event_outbox')
            ->where('routing_key', 'merchant_product_group.updated')
            ->count();

        $this->assertGreaterThan(
            $beforeCount,
            $afterCount,
            'Outbox observer should publish merchant_product_group.updated for each Eloquent update()'
        );

        $this->assertSame(5, $g->fresh()->sort_order);
    }

    /** @test */
    public function it_validates_items_must_not_be_empty(): void
    {
        $mutation = '
            mutation Reorder($items: [MerchantProductGroupPositionInput!]!) {
                MerchantProductGroupChangePosition(items: $items) {
                    code
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, ['items' => []]);

        $response->assertJsonStructure(['errors']);
    }

    /** @test */
    public function it_validates_id_must_exist(): void
    {
        $mutation = '
            mutation Reorder($items: [MerchantProductGroupPositionInput!]!) {
                MerchantProductGroupChangePosition(items: $items) {
                    code
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'items' => [['id' => 999999999, 'sort_order' => 0]],
            ]);

        $response->assertJsonStructure(['errors']);
    }

    /** @test */
    public function it_rejects_soft_deleted_group_id(): void
    {
        $group = $this->createGroup(0);
        $group->delete(); // soft-delete

        $mutation = '
            mutation Reorder($items: [MerchantProductGroupPositionInput!]!) {
                MerchantProductGroupChangePosition(items: $items) {
                    code
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'items' => [['id' => $group->id, 'sort_order' => 0]],
            ]);

        $response->assertJsonStructure(['errors']);
    }

    /** @test */
    public function it_forbids_shop_role_user_from_reordering_other_shop_groups(): void
    {
        $shopUser = $this->makeShopUser($this->shop);

        $otherShop = Shop::create([
            'title' => 'Other Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $foreignGroup = MerchantProductGroup::create([
            'shop_id' => $otherShop->id,
            'title' => 'Foreign Group ' . uniqid(),
            'show_on_main' => false,
            'show_on_merchant_page' => true,
            'show_in_merchant_catalog' => false,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $mutation = '
            mutation Reorder($items: [MerchantProductGroupPositionInput!]!) {
                MerchantProductGroupChangePosition(items: $items) {
                    code
                }
            }
        ';

        $response = $this->actingAs($shopUser)
            ->graphQL($mutation, [
                'items' => [['id' => $foreignGroup->id, 'sort_order' => 5]],
            ]);

        $response->assertJsonStructure(['errors']);
        $this->assertSame(0, $foreignGroup->fresh()->sort_order, 'foreign group must NOT be updated');
    }

    /** @test */
    public function it_rejects_duplicate_ids_in_items(): void
    {
        $g = $this->createGroup(0);

        $mutation = '
            mutation Reorder($items: [MerchantProductGroupPositionInput!]!) {
                MerchantProductGroupChangePosition(items: $items) {
                    code
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'items' => [
                    ['id' => $g->id, 'sort_order' => 0],
                    ['id' => $g->id, 'sort_order' => 1],
                ],
            ]);

        $response->assertJsonStructure(['errors']);
    }
}
