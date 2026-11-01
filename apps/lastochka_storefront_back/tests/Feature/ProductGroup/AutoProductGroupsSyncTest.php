<?php

declare(strict_types=1);

namespace Tests\Feature\ProductGroup;

use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Enums\YandexClaimStatus;
use App\Events\OrderStatusChangedEvent;
use App\Jobs\SendOrderStatusNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Models\User;
use App\Models\YandexDeliveryClaim;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use App\RabbitMQ\Transformers\ProductGroupEventTransformer;
use App\Services\YandexDelivery\FakeYandexGoClient;
use App\Services\YandexDelivery\YandexGoClientInterface;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Данные автогрупп из шины и смены статусов: fill_type группы, галочки
 * товара is_new / is_hurry_up, orders.delivered_at.
 */
final class AutoProductGroupsSyncTest extends TestCase
{
    use RefreshDatabase;

    // ==================== ProductGroupEventTransformer ====================

    public function test_group_fill_type_mapping(): void
    {
        $transformer = new ProductGroupEventTransformer;

        $this->assertSame('viewed_not_bought', $transformer->fromEvent($this->groupPayload(['fill_type' => 'viewed_not_bought']))['fill_type']);
        $this->assertSame('is_new', $transformer->fromEvent($this->groupPayload(['fill_type' => 'is_new']))['fill_type']);
        $this->assertSame('manual', $transformer->fromEvent($this->groupPayload())['fill_type']);
        $this->assertSame('manual', $transformer->fromEvent($this->groupPayload(['fill_type' => 'top_sellers']))['fill_type']);
        $this->assertSame('manual', $transformer->fromEvent($this->groupPayload(['fill_type' => null]))['fill_type']);
    }

    public function test_switching_group_to_auto_fill_type_drops_manual_items(): void
    {
        Storage::fake('public');
        $store = Store::factory()->create(['code' => 'MSK_AUTO']);
        $product = Product::factory()->create(['store_id' => $store->id]);
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_auto',
            'source' => 'bagisto',
            'shop_code' => 'MSK_AUTO',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $consumer = new EventConsumer('merchant_product_groups', config('rabbitmq-events.entities.merchant_product_groups'));

        $this->assertSame('ack', $consumer->processEvent($this->groupPayload([
            'event_type' => 'created',
            'id' => 'group_auto',
            'shop_id' => 'MSK_AUTO',
            'products' => [['product_id' => 'prod_auto', 'product_source' => 'bagisto']],
        ])));

        $group = ProductGroup::query()->where('store_id', $store->id)->sole();
        $this->assertSame('manual', $group->fill_type);
        $this->assertCount(1, $group->items);

        $this->assertSame('ack', $consumer->processEvent($this->groupPayload([
            'event_type' => 'updated',
            'event_timestamp' => now()->addSecond()->toIso8601String(),
            'id' => 'group_auto',
            'shop_id' => 'MSK_AUTO',
            'fill_type' => 'is_hurry_up',
            'products' => [['product_id' => 'prod_auto', 'product_source' => 'bagisto']],
        ])));

        $group->refresh();
        $this->assertSame('is_hurry_up', $group->fill_type);
        $this->assertCount(0, $group->items);
    }

    // ==================== ProductEventTransformer ====================

    public function test_product_flags_set_when_keys_present(): void
    {
        $store = Store::factory()->create();

        $attributes = app(ProductEventTransformer::class)->fromEvent(array_merge(
            $this->productPayload($store),
            ['is_new' => true, 'is_hurry_up' => false],
        ));

        $this->assertTrue($attributes['is_new']);
        $this->assertArrayHasKey('is_hurry_up', $attributes);
        $this->assertFalse($attributes['is_hurry_up']);
    }

    public function test_partial_product_event_does_not_reset_flags(): void
    {
        $store = Store::factory()->create();

        $attributes = app(ProductEventTransformer::class)->fromEvent($this->productPayload($store));

        $this->assertArrayNotHasKey('is_new', $attributes);
        $this->assertArrayNotHasKey('is_hurry_up', $attributes);
    }

    // ==================== delivered_at ====================

    public function test_before_update_sets_delivered_at_on_transition_to_delivered(): void
    {
        Bus::fake([SendOrderStatusNotification::class]);
        Carbon::setTestNow('2026-10-08 12:00:00');

        $order = $this->order(OrderStatus::InDelivery);

        $result = (new OrderEventTransformer)->beforeUpdate(
            $order,
            ['event_id' => Str::uuid()->toString(), 'event_type' => 'updated'],
            ['status' => OrderStatus::Delivered],
        );

        $this->assertSame('2026-10-08 12:00:00', $result['delivered_at']->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_before_update_keeps_existing_delivered_at_and_ignores_other_statuses(): void
    {
        Bus::fake([SendOrderStatusNotification::class]);
        $transformer = new OrderEventTransformer;
        $event = ['event_id' => Str::uuid()->toString(), 'event_type' => 'updated'];

        $alreadySet = $this->order(OrderStatus::InDelivery, ['delivered_at' => now()->subDay()]);
        $result = $transformer->beforeUpdate($alreadySet, $event, ['status' => OrderStatus::Delivered]);
        $this->assertArrayNotHasKey('delivered_at', $result);

        $assembling = $this->order(OrderStatus::Pending);
        $result = $transformer->beforeUpdate($assembling, $event, ['status' => OrderStatus::Assembling]);
        $this->assertArrayNotHasKey('delivered_at', $result);

        // Повторное delivered по уже доставленному заказу — статус не меняется
        $delivered = $this->order(OrderStatus::Delivered);
        $result = $transformer->beforeUpdate($delivered, $event, ['status' => OrderStatus::Delivered]);
        $this->assertArrayNotHasKey('delivered_at', $result);
    }

    public function test_status_changed_event_sets_delivered_at(): void
    {
        Bus::fake([SendOrderStatusNotification::class]);
        Event::fake([OrderStatusChangedEvent::class]);

        $order = $this->order(OrderStatus::InDelivery);
        $preset = $this->order(OrderStatus::InDelivery, ['delivered_at' => now()->subDays(2)->startOfSecond()]);
        $presetValue = $preset->delivered_at->copy();

        $transformer = new OrderEventTransformer;
        $transformer->processStatusChangedEvent($order, ['status' => 'delivered']);
        $transformer->processStatusChangedEvent($preset, ['status' => 'delivered']);

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->delivered_at);
        $this->assertTrue($preset->fresh()->delivered_at->equalTo($presetValue));
    }

    public function test_status_changed_event_to_other_status_keeps_delivered_at_empty(): void
    {
        Bus::fake([SendOrderStatusNotification::class]);
        Event::fake([OrderStatusChangedEvent::class]);

        $order = $this->order(OrderStatus::Pending);
        (new OrderEventTransformer)->processStatusChangedEvent($order, ['status' => 'assembling']);

        $this->assertNull($order->fresh()->delivered_at);
    }

    public function test_yandex_webhook_sets_delivered_at(): void
    {
        /** @var FakeYandexGoClient $fakeYandex */
        $fakeYandex = $this->app->make(YandexGoClientInterface::class);

        $order = $this->order(OrderStatus::InDelivery, ['shipping_method' => ShippingMethod::YandexDelivery]);
        YandexDeliveryClaim::create([
            'order_id' => $order->id,
            'claim_id' => 'claim_delivered_at',
            'status' => YandexClaimStatus::Pickuped,
            'due_at' => now()->addHour(),
        ]);
        $fakeYandex->claimStatuses['claim_delivered_at'] = 'delivered';

        $this->postJson('/api/v1/webhooks/yandex-delivery', [
            'claim_id' => 'claim_delivered_at',
            'status' => 'delivered',
            'updated_ts' => now()->addSecond()->toIso8601String(),
        ])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertNotNull($order->delivered_at);
    }

    public function test_migration_backfills_delivered_at_from_updated_at(): void
    {
        $delivered = $this->order(OrderStatus::Delivered);
        $cancelled = $this->order(OrderStatus::Cancelled);
        DB::table('orders')->whereIn('id', [$delivered->id, $cancelled->id])
            ->update(['delivered_at' => null, 'updated_at' => '2026-09-01 10:00:00']);

        $migration = require database_path('migrations/2026_10_08_100200_add_delivered_at_to_orders_table.php');
        $migration->up();

        $this->assertSame('2026-09-01 10:00:00', $delivered->fresh()->delivered_at->format('Y-m-d H:i:s'));
        $this->assertNull($cancelled->fresh()->delivered_at);
    }

    // ==================== Helpers ====================

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(OrderStatus $status, array $attributes = []): Order
    {
        return Order::factory()
            ->for(User::factory())
            ->for(Store::factory()->state(['code' => 'MSK_'.Str::random(4)]))
            ->create(array_merge(['status' => $status], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function groupPayload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'group_'.Str::random(5),
            'source' => 'bagisto',
            'shop_id' => 'doesnt-matter',
            'shop_source' => 'bagisto',
            'title' => 'Группа',
            'is_active' => true,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(Store $store): array
    {
        return [
            'shop_code' => $store->code,
            'source' => $store->source ?? 'admin',
            'sku' => 'SKU-NEW-1',
            'title' => 'Сырок',
            'price' => 90.0,
        ];
    }
}
