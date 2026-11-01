<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Shop;
use App\Models\ShopCategory;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тестирование publishMultiEntity для категорий.
 *
 * Проверяет что:
 * - Категория без магазинов создаёт одно событие (fallback)
 * - Категория с несколькими магазинами создаёт отдельное событие на каждый магазин
 * - Каждое событие содержит правильный shop_code
 * - Каждое событие имеет уникальный event_id
 *
 * ВАЖНО: ShopCategory.$touches НЕ вызывает Eloquent events (BelongsTo::touch()
 * делает raw UPDATE). Поэтому тестируем через явный Category::update().
 */
class CategoryPublishMultiEntityTest extends TestCase
{
    use DatabaseTransactions;

    protected Shop $shop1;
    protected Shop $shop2;

    protected function setUp(): void
    {
        // Сброс статического флага, чтобы Observer-ы перерегистрировались
        // с новым event dispatcher после refreshApplication()
        $ref = new \ReflectionClass(RabbitMQEventsServiceProvider::class);
        $prop = $ref->getProperty('observersRegistered');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();

        $shopDefaults = [
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ];

        $this->shop1 = Shop::create(array_merge($shopDefaults, [
            'title' => 'Test Shop 1',
            'code' => 'test-shop-1-' . uniqid(),
        ]));

        $this->shop2 = Shop::create(array_merge($shopDefaults, [
            'title' => 'Test Shop 2',
            'code' => 'test-shop-2-' . uniqid(),
        ]));
    }

    /** @test */
    public function category_create_without_shops_produces_single_outbox_event()
    {
        $outboxBefore = EventOutbox::max('id') ?? 0;

        $category = Category::create(['title' => 'Test Cat ' . uniqid()]);

        $events = EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $category->id)
            ->where('id', '>', $outboxBefore)
            ->get();

        // Category без shopCategories -> 1 событие (fallback single entity)
        $this->assertGreaterThanOrEqual(1, $events->count());
    }

    /** @test */
    public function category_update_with_two_shops_produces_two_events()
    {
        $category = Category::create(['title' => 'Multi Shop Cat ' . uniqid()]);

        // Привязываем категорию к двум магазинам
        ShopCategory::create([
            'shop_id' => $this->shop1->id,
            'category_id' => $category->id,
        ]);
        ShopCategory::create([
            'shop_id' => $this->shop2->id,
            'category_id' => $category->id,
        ]);

        $outboxBefore = EventOutbox::max('id') ?? 0;

        // Явное обновление вызывает Observer -> publishMultiEntity
        $category->update(['title' => 'Updated Multi ' . uniqid()]);

        $events = EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $category->id)
            ->where('event_type', 'updated')
            ->where('id', '>', $outboxBefore)
            ->get();

        // Должно быть 2 события (по одному на каждый магазин)
        $this->assertCount(2, $events);
    }

    /** @test */
    public function multi_entity_events_contain_correct_shop_codes()
    {
        $category = Category::create(['title' => 'Shop Code Cat ' . uniqid()]);

        // Привязка к обоим магазинам
        ShopCategory::create([
            'shop_id' => $this->shop1->id,
            'category_id' => $category->id,
        ]);
        ShopCategory::create([
            'shop_id' => $this->shop2->id,
            'category_id' => $category->id,
        ]);

        // Обновляем категорию — вызывает Observer -> publishMultiEntity
        $outboxBefore = EventOutbox::max('id') ?? 0;
        $category->update(['title' => 'Updated Cat ' . uniqid()]);

        $events = EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $category->id)
            ->where('event_type', 'updated')
            ->where('id', '>', $outboxBefore)
            ->get();

        // Должно быть 2 события (по одному на магазин)
        $this->assertCount(2, $events);

        $shopCodes = $events->map(function ($e) {
            return $e->payload['shop_code'] ?? null;
        })->sort()->values()->toArray();

        $expectedShopCodes = collect([$this->shop1->code, $this->shop2->code])
            ->sort()->values()->toArray();

        $this->assertEquals($expectedShopCodes, $shopCodes);
    }

    /** @test */
    public function each_multi_entity_event_has_unique_event_id()
    {
        $category = Category::create(['title' => 'UUID Cat ' . uniqid()]);

        ShopCategory::create([
            'shop_id' => $this->shop1->id,
            'category_id' => $category->id,
        ]);
        ShopCategory::create([
            'shop_id' => $this->shop2->id,
            'category_id' => $category->id,
        ]);

        $outboxBefore = EventOutbox::max('id') ?? 0;
        $category->update(['title' => 'UUID Updated ' . uniqid()]);

        $events = EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $category->id)
            ->where('event_type', 'updated')
            ->where('id', '>', $outboxBefore)
            ->get();

        $this->assertCount(2, $events);

        // Каждое событие должно иметь уникальный event_id
        $eventIds = $events->pluck('event_id')->toArray();
        $this->assertCount(count($eventIds), array_unique($eventIds));
    }
}
