<?php

namespace Tests\Feature;

use App\Events\ShopCategoryDetached;
use App\Models\Category;
use App\Models\Shop;
use App\Services\ShopService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Покрывает корневой баг ALADDIN-9822: при отвязке категории от магазина
 * event для RabbitMQ не публиковался (touch() после sync() — pivot уже пуст,
 * Observer не видит связь). Фикс: Laravel-event ShopCategoryDetached +
 * listener, публикующий custom RabbitMQ-событие `category.shop_detached`
 * ДО sync().
 */
class ShopCategoryDetachEventTest extends TestCase
{
    use DatabaseTransactions;

    private Shop $shop;
    private Category $catA;
    private Category $catB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'title' => 'Detach Test Shop ' . uniqid(),
            'code' => 'detach-test-' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $this->catA = Category::create([
            'title' => 'Detach Cat A ' . uniqid(),
            'parent_id' => null,
        ]);
        $this->catB = Category::create([
            'title' => 'Detach Cat B ' . uniqid(),
            'parent_id' => null,
        ]);
    }

    /** @test */
    public function laravel_event_is_dispatched_per_detached_category()
    {
        Event::fake([ShopCategoryDetached::class]);

        $service = new ShopService();

        // Сначала привязываем обе
        $service->syncCategoriesWithEvents($this->shop, [$this->catA->id, $this->catB->id]);

        // Теперь оставляем только catA — catB должна быть отвязана
        $service->syncCategoriesWithEvents($this->shop, [$this->catA->id]);

        Event::assertDispatchedTimes(ShopCategoryDetached::class, 1);
        Event::assertDispatched(
            ShopCategoryDetached::class,
            fn(ShopCategoryDetached $e) => $e->category->id === $this->catB->id
                && $e->shop->id === $this->shop->id
        );
    }

    /** @test */
    public function no_event_dispatched_when_nothing_detached()
    {
        Event::fake([ShopCategoryDetached::class]);

        $service = new ShopService();

        $service->syncCategoriesWithEvents($this->shop, [$this->catA->id]);
        $service->syncCategoriesWithEvents($this->shop, [$this->catA->id, $this->catB->id]);

        Event::assertNotDispatched(ShopCategoryDetached::class);
    }

    /** @test */
    public function outbox_receives_shop_detached_event_with_correct_payload()
    {
        $service = new ShopService();

        $service->syncCategoriesWithEvents($this->shop, [$this->catA->id, $this->catB->id]);
        $service->syncCategoriesWithEvents($this->shop, [$this->catA->id]);

        $outbox = EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $this->catB->id)
            ->where('event_type', 'shop_detached')
            ->first();

        $this->assertNotNull($outbox, 'shop_detached outbox row must exist for detached category');
        $this->assertSame('category.shop_detached', $outbox->routing_key);

        $payload = $outbox->payload;
        $this->assertSame('shop_detached', $payload['event_type']);
        $this->assertSame($this->shop->code, $payload['shop_code']);
        $this->assertSame($this->catB->title, $payload['title']);
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('event_timestamp', $payload);
    }
}
