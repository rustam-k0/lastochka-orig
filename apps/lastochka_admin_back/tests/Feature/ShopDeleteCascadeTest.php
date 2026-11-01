<?php

namespace Tests\Feature;

use App\Models\MerchantBanner;
use App\Models\MerchantProductGroup;
use App\Models\MerchantPromocode;
use App\Models\MerchantReview;
use App\Models\MerchantSale;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ShopDeleteCascadeTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;

    protected function setUp(): void
    {
        // Провайдер rabbitmq-пакета регистрирует observers один раз на PHP-процесс
        // (static-флаг), а PHPUnit пересоздаёт приложение на каждый тест — без сброса
        // флага outbox-события не пишутся во всех тестах после первого.
        $registered = new \ReflectionProperty(
            \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
            'observersRegistered'
        );
        $registered->setAccessible(true);
        $registered->setValue(null, false);

        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();
    }

    private const MUTATION = '
        mutation ($id: Int) {
            ShopDelete(id: $id) {
                code
                message
            }
        }
    ';

    private function createShopWithWorker(): array
    {
        $shop = Shop::create([
            'title' => 'Магазин ' . uniqid(),
        ]);

        $binding = UserShop::create([
            'shop_id' => $shop->id,
            'user_id' => $this->adminUser->id,
        ]);

        return [$shop, $binding];
    }

    /** @test */
    public function shop_delete_mutation_soft_deletes_user_shop_bindings()
    {
        [$shop, $binding] = $this->createShopWithWorker();

        $response = $this->actingAs($this->adminUser)
            ->graphQL(self::MUTATION, ['id' => $shop->id]);

        $response->assertJsonMissing(['errors']);

        // Регрессия: привязка сотрудника оставалась живой после удаления
        // магазина → user.shops.shop = null в GraphQL → форма сотрудника
        // в admin_front падала и показывала пустой список магазинов.
        $this->assertSoftDeleted('shops', ['id' => $shop->id]);
        $this->assertSoftDeleted('user_shops', ['id' => $binding->id]);
    }

    /** @test */
    public function direct_model_delete_soft_deletes_user_shop_bindings()
    {
        [$shop, $binding] = $this->createShopWithWorker();

        $shop->delete();

        $this->assertSoftDeleted('user_shops', ['id' => $binding->id]);
    }

    /** @test */
    public function shop_delete_cascades_merchant_entities()
    {
        $shop = Shop::create(['title' => 'Магазин ' . uniqid()]);

        $group = MerchantProductGroup::create([
            'shop_id' => $shop->id,
            'title' => 'Группа ' . uniqid(),
            'is_active' => true,
        ]);
        $banner = MerchantBanner::create([
            'shop_id' => $shop->id,
            'title' => 'Баннер ' . uniqid(),
            'link_type' => MerchantBanner::LINK_TYPE_NONE,
            'is_active' => true,
            'position' => 0,
        ]);
        $sale = MerchantSale::create([
            'shop_id' => $shop->id,
            'title' => 'Акция ' . uniqid(),
            'discount_percent' => 10,
            'starts_at' => now(),
            'ends_at' => now()->addWeek(),
            'is_active' => true,
        ]);
        $review = MerchantReview::create([
            'shop_id' => $shop->id,
            'rating' => 5,
            'is_published' => false,
        ]);
        $promocode = MerchantPromocode::create([
            'shop_id' => $shop->id,
            'title' => 'Тестовый промокод',
            'code' => 'E2E' . strtoupper(uniqid()),
            'discount_percent' => 10,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'valid_from' => now(),
            'valid_to' => now()->addWeek(),
        ]);

        $shop->delete();

        // Регрессия ALADDIN-11154: группы удалённого магазина оставались живыми,
        // shop: Shop! резолвился в null и ронял весь список merchantProductGroups.
        $this->assertSoftDeleted('merchant_product_groups', ['id' => $group->id]);
        $this->assertSoftDeleted('merchant_banners', ['id' => $banner->id]);
        $this->assertSoftDeleted('merchant_sales', ['id' => $sale->id]);
        $this->assertSoftDeleted('merchant_reviews', ['id' => $review->id]);
        $this->assertSoftDeleted('merchant_promocodes', ['id' => $promocode->id]);
    }

    /** @test */
    public function merchant_entity_cascade_emits_deleted_events_to_outbox()
    {
        $shop = Shop::create(['title' => 'Магазин ' . uniqid()]);
        $group = MerchantProductGroup::create([
            'shop_id' => $shop->id,
            'title' => 'Группа ' . uniqid(),
            'is_active' => true,
        ]);

        $shop->delete();

        // Каскад обязан идти поштучно через Eloquent, чтобы outbox-события
        // деактивации улетели в storefront, а не молча умерли в bulk-запросе.
        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'merchant_product_groups',
            'entity_id' => $group->id,
            'event_type' => 'deleted',
        ]);
    }

    /** @test */
    public function delete_does_not_touch_bindings_of_other_shops()
    {
        [$shop] = $this->createShopWithWorker();
        [$otherShop, $otherBinding] = $this->createShopWithWorker();

        $shop->delete();

        $this->assertNull($otherShop->fresh()->deleted_at);
        $this->assertNull(UserShop::findOrFail($otherBinding->id)->deleted_at);
    }
}
