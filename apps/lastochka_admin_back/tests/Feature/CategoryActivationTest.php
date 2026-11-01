<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Category\CategoryHide;
use App\GraphQL\Mutations\Category\CategoryPublish;
use App\Models\Category;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Деактивация категорий (feat/category-deactivation).
 *
 * Проверяет что:
 * - CategoryHide ставит is_active=false и публикует category.updated с active=false
 * - CategoryPublish возвращает is_active=true и публикует active=true
 * - Обычный update с is_active тоже доносит флаг до payload (путь формы CategoryUpdate)
 * - Категория по умолчанию создаётся активной, payload содержит active=true
 */
class CategoryActivationTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected Shop $shop;

    protected function setUp(): void
    {
        // Сброс статического флага, чтобы Observer-ы перерегистрировались
        // с новым event dispatcher после refreshApplication()
        $ref = new \ReflectionClass(RabbitMQEventsServiceProvider::class);
        $prop = $ref->getProperty('observersRegistered');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();

        $this->shop = Shop::create([
            'title' => 'Activation Shop',
            'code' => 'activation-shop-' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    protected function makeCategory(): Category
    {
        $category = Category::create(['title' => 'Activation Cat ' . uniqid()]);

        ShopCategory::create([
            'shop_id' => $this->shop->id,
            'category_id' => $category->id,
        ]);

        return $category;
    }

    protected function lastUpdatedPayload(Category $category, int $afterId): ?array
    {
        $event = EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $category->id)
            ->where('event_type', 'updated')
            ->where('id', '>', $afterId)
            ->orderByDesc('id')
            ->first();

        if (!$event) {
            return null;
        }

        $payload = $event->payload;

        return is_array($payload) ? $payload : json_decode((string) $payload, true);
    }

    /** @test */
    public function category_is_created_active_by_default()
    {
        $category = $this->makeCategory();

        $this->assertTrue((bool) $category->fresh()->is_active);
    }

    /** @test */
    public function category_hide_deactivates_and_publishes_active_false()
    {
        $category = $this->makeCategory();
        $outboxBefore = EventOutbox::max('id') ?? 0;

        $response = (new CategoryHide())(null, ['id' => $category->id]);

        $this->assertSame(200, $response['code']);
        $this->assertFalse((bool) $category->fresh()->is_active);

        $payload = $this->lastUpdatedPayload($category, $outboxBefore);
        $this->assertNotNull($payload, 'CategoryHide должен публиковать category.updated в outbox');
        $this->assertArrayHasKey('active', $payload);
        $this->assertFalse($payload['active']);
    }

    /** @test */
    public function category_publish_activates_and_publishes_active_true()
    {
        $category = $this->makeCategory();
        $category->update(['is_active' => false]);

        $outboxBefore = EventOutbox::max('id') ?? 0;

        $response = (new CategoryPublish())(null, ['id' => $category->id]);

        $this->assertSame(200, $response['code']);
        $this->assertTrue((bool) $category->fresh()->is_active);

        $payload = $this->lastUpdatedPayload($category, $outboxBefore);
        $this->assertNotNull($payload, 'CategoryPublish должен публиковать category.updated в outbox');
        $this->assertTrue($payload['active']);
    }

    /** @test */
    public function category_update_with_is_active_reaches_outbox_payload()
    {
        // Путь формы админки: CategoryUpdate резолвер делает $item->update($args)
        $category = $this->makeCategory();
        $outboxBefore = EventOutbox::max('id') ?? 0;

        $category->update(['is_active' => false, 'title' => $category->title]);

        $payload = $this->lastUpdatedPayload($category, $outboxBefore);
        $this->assertNotNull($payload);
        $this->assertFalse($payload['active']);
    }

    /** @test */
    public function payload_active_is_true_for_untouched_category()
    {
        $category = $this->makeCategory();
        $outboxBefore = EventOutbox::max('id') ?? 0;

        $category->update(['title' => 'Renamed ' . uniqid()]);

        $payload = $this->lastUpdatedPayload($category, $outboxBefore);
        $this->assertNotNull($payload);
        $this->assertTrue($payload['active']);
    }

    /** @test */
    public function category_hide_and_publish_work_through_graphql_endpoint()
    {
        // Полная проводка: схема → guard/can → валидатор → резолвер
        $this->actingAs($this->adminUser());
        $category = $this->makeCategory();

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int) {
                CategoryHide(id: $id) { code }
            }
        ', ['id' => $category->id])->assertJsonPath('data.CategoryHide.code', 200);

        $this->assertFalse((bool) $category->fresh()->is_active);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int) {
                CategoryPublish(id: $id) { code }
            }
        ', ['id' => $category->id])->assertJsonPath('data.CategoryPublish.code', 200);

        $this->assertTrue((bool) $category->fresh()->is_active);
    }

    /** @test */
    public function category_hide_rejects_unknown_id()
    {
        $this->actingAs($this->adminUser());

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int) {
                CategoryHide(id: $id) { code }
            }
        ', ['id' => 99999999]);

        $this->assertSame(
            'validation',
            $response->json('errors.0.extensions.category')
        );
    }
}
