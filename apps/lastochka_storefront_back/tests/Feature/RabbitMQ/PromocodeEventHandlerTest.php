<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Category;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PromocodeEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('merchant_promocodes', config('rabbitmq-events.entities.merchant_promocodes'));
    }

    public function test_promocode_created_event_creates_promocode(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_001',
            'shop_source' => 'bagisto',
            'title' => 'Скидка 15%',
            'code' => 'SUMMER15',
            'discount_percent' => 15,
            'valid_from' => now()->toIso8601String(),
            'valid_to' => now()->addMonth()->toIso8601String(),
            'is_active' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('promocodes', [
            'store_id' => $store->id,
            'source' => 'bagisto',
            'title' => 'Скидка 15%',
            'code' => 'SUMMER15',
            'type' => 'percent',
            'value' => 15,
            'is_active' => true,
        ]);
    }

    public function test_promocode_updated_event_updates_promocode(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_002']);
        $promocode = Promocode::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'promo_update',
            'code' => 'OLD_CODE',
            'value' => 10,
            'is_active' => true,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_promocodes',
            'entity_id' => $promocode->id,
            'external_id' => 'promo_update',
            'source' => 'bagisto',
            'shop_code' => 'MSK_002',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'promo_update',
            'shop_id' => 'MSK_002',
            'shop_source' => 'bagisto',
            'title' => 'Обновлённый промокод',
            'code' => 'OLD_CODE',
            'discount_percent' => 25,
            'is_active' => false,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $promocode->refresh();
        $this->assertEquals('Обновлённый промокод', $promocode->title);
        $this->assertEquals(25, $promocode->value);
        $this->assertFalse($promocode->is_active);
    }

    public function test_promocode_deleted_event_deletes_promocode(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_003']);
        $promocode = Promocode::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'promo_delete',
            'code' => 'DELETE_ME',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_promocodes',
            'entity_id' => $promocode->id,
            'external_id' => 'promo_delete',
            'source' => 'bagisto',
            'shop_code' => 'MSK_003',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'promo_delete',
            'shop_id' => 'MSK_003',
            'shop_source' => 'bagisto',
            'title' => 'Удаляемый промокод',
            'code' => 'DELETE_ME',
            'discount_percent' => 10,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        // Promocode не использует SoftDeletes, поэтому проверяем полное удаление
        $this->assertDatabaseMissing('promocodes', ['id' => $promocode->id]);
    }

    public function test_deleted_event_with_orphan_metadata_deletes_promocode(): void
    {
        // Метаданные есть, но entity_id потерян: пакет ищет сущность через
        // findExistingEntity($eventData, []) — store_id берётся из события
        $store = Store::factory()->create(['code' => 'MSK_005']);
        $promocode = Promocode::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'promo_orphan',
            'code' => 'ORPHAN_ME',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_promocodes',
            'entity_id' => null,
            'external_id' => 'promo_orphan',
            'source' => 'bagisto',
            'shop_code' => config('rabbitmq-events.shop_code'),
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $result = $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'promo_orphan',
            'shop_id' => 'MSK_005',
            'shop_source' => 'bagisto',
            'code' => 'ORPHAN_ME',
        ]));

        $this->assertEquals('ack', $result);

        // Promocode не использует SoftDeletes, поэтому проверяем полное удаление
        $this->assertDatabaseMissing('promocodes', ['id' => $promocode->id]);
    }

    public function test_existing_promocode_found_by_code_in_same_store(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_004']);
        $existingPromocode = Promocode::factory()->create([
            'store_id' => $store->id,
            'code' => 'EXISTING_CODE',
            'title' => 'Старый промокод',
            'value' => 5,
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'new_external_id',
            'shop_id' => 'MSK_004',
            'shop_source' => 'bagisto',
            'title' => 'Новое название промокода',
            'code' => 'EXISTING_CODE',
            'discount_percent' => 20,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        // Промокод должен быть только один
        $this->assertEquals(1, Promocode::where('store_id', $store->id)->count());

        // Данные должны быть обновлены
        $existingPromocode->refresh();
        $this->assertEquals('Новое название промокода', $existingPromocode->title);
        $this->assertEquals(20, $existingPromocode->value);
    }

    public function test_store_not_found_returns_null(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'NON_EXISTENT_SHOP',
            'shop_source' => 'bagisto',
            'title' => 'Промокод без магазина',
            'code' => 'NO_STORE',
            'discount_percent' => 10,
        ]);

        $this->consumer->processEvent($eventData);

        $this->assertDatabaseMissing('promocodes', [
            'code' => 'NO_STORE',
        ]);
    }

    public function test_duplicate_event_is_skipped(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_005']);
        $eventId = Str::uuid()->toString();

        $eventData = $this->buildEventData([
            'event_id' => $eventId,
            'event_type' => 'created',
            'shop_id' => 'MSK_005',
            'shop_source' => 'bagisto',
            'title' => 'Уникальный промокод',
            'code' => 'UNIQUE_'.Str::random(5),
            'discount_percent' => 10,
        ]);

        $result1 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result1);

        $result2 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result2);

        $this->assertEquals(1, Promocode::where('store_id', $store->id)->count());
    }

    public function test_promocode_created_with_fixed_discount_type(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_FIXED']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_FIXED',
            'shop_source' => 'bagisto',
            'title' => 'Скидка 500₽',
            'code' => 'FIXED500',
            'discount_type' => 'fixed',
            'discount_value' => 500,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('promocodes', [
            'store_id' => $store->id,
            'code' => 'FIXED500',
            'type' => 'fixed',
            'value' => 500,
        ]);
    }

    public function test_promocode_created_with_product_binding(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_BIND']);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create();

        // Создаём metadata для product, чтобы resolveEntityId мог найти его
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'ext_product_1',
            'source' => 'bagisto',
            'shop_code' => 'MSK_BIND',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_BIND',
            'shop_source' => 'bagisto',
            'title' => 'Промокод на товар',
            'code' => 'BIND_PROD',
            'discount_percent' => 15,
            'binding_type' => 'products',
            'products' => [
                ['product_id' => 'ext_product_1', 'product_source' => 'bagisto'],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $promocode = Promocode::where('code', 'BIND_PROD')->first();
        $this->assertNotNull($promocode);
        $this->assertEquals('products', $promocode->binding_type);
        $this->assertTrue($promocode->products->contains('id', $product->id));
    }

    public function test_promocode_created_event_maps_usage_limits(): void
    {
        Store::factory()->create(['code' => 'MSK_LIMITS']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_LIMITS',
            'shop_source' => 'bagisto',
            'title' => 'Промокод с лимитами',
            'code' => 'LIMITED',
            'discount_percent' => 15,
            'max_uses' => 100,
            'max_uses_per_client' => 1,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('promocodes', [
            'code' => 'LIMITED',
            'max_uses' => 100,
            'max_uses_per_client' => 1,
        ]);
    }

    public function test_promocode_created_event_without_limits_keeps_fields_null(): void
    {
        Store::factory()->create(['code' => 'MSK_NOLIMIT']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_NOLIMIT',
            'shop_source' => 'bagisto',
            'title' => 'Без лимитов',
            'code' => 'NOLIMIT_EVT',
            'discount_percent' => 10,
        ]);
        // max_uses / max_uses_per_client отсутствуют в payload

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $promocode = Promocode::where('code', 'NOLIMIT_EVT')->first();
        $this->assertNotNull($promocode);
        $this->assertNull($promocode->max_uses);
        $this->assertNull($promocode->max_uses_per_client);
    }

    public function test_backward_compat_old_event_format(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_COMPAT']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_COMPAT',
            'shop_source' => 'bagisto',
            'title' => 'Старый формат',
            'code' => 'OLD_FORMAT',
            'discount_percent' => 15,
        ]);

        // Убираем новые поля, чтобы симулировать старый формат
        unset($eventData['discount_type'], $eventData['discount_value'], $eventData['binding_type']);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('promocodes', [
            'store_id' => $store->id,
            'code' => 'OLD_FORMAT',
            'type' => 'percent',
            'value' => 15,
            'binding_type' => 'all',
        ]);
    }

    /**
     * Построить данные события с дефолтными значениями.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'promo_'.Str::random(5),
            'source' => 'bagisto',
            'shop_id' => 'TEST_'.Str::random(3),
            'shop_source' => 'bagisto',
            'title' => 'Тестовый промокод',
            'code' => 'TEST_'.Str::random(5),
            'discount_percent' => 10,
            'valid_from' => now()->toIso8601String(),
            'valid_to' => now()->addMonth()->toIso8601String(),
            'is_active' => true,
        ], $overrides);
    }
}
