<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Category;
use App\Models\Store;
use App\RabbitMQ\Debug\LoggingEventConsumer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Покрывает orphan-recover в LoggingEventConsumer.
 *
 * Сценарий: metadata указывает на entity_id, которого физически нет в БД
 * (force delete, ручная чистка). Без recover'а vendor EventConsumer бросает
 * RuntimeException("Entity not found: <id>") → 3 retry → DLQ навсегда.
 */
final class LoggingEventConsumerOrphanRecoverTest extends TestCase
{
    use RefreshDatabase;

    private LoggingEventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumer = new LoggingEventConsumer(
            'categories',
            config('rabbitmq-events.entities.categories'),
            'storefront_categories',
        );
    }

    public function test_recovers_from_metadata_pointing_to_missing_row_and_creates_new_entity(): void
    {
        $store = Store::factory()->create(['code' => 'ORPHAN_SHOP']);
        $missingEntityId = 99999;
        $externalId = 'admin-cat-101';

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $missingEntityId,
            'external_id' => $externalId,
            'source' => 'bagisto',
            'shop_code' => 'ORPHAN_SHOP',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);
        $this->assertDatabaseMissing('categories', ['id' => $missingEntityId]);

        $result = $this->consumer->processEvent($this->payload([
            'id' => $externalId,
            'shop_code' => 'ORPHAN_SHOP',
            'title' => 'Recovered category',
        ]));

        $this->assertSame('ack', $result);

        // Категория создалась (с новым id, не 99999)
        $created = Category::where('name', 'Recovered category')->first();
        $this->assertNotNull($created);
        $this->assertNotSame($missingEntityId, $created->id);

        // Метадата теперь указывает на новый id
        $meta = EventSyncMetadata::where('entity_type', 'categories')
            ->where('external_id', $externalId)
            ->where('source', 'bagisto')
            ->first();
        $this->assertNotNull($meta);
        $this->assertSame($created->id, $meta->entity_id);

        unset($store);
    }

    public function test_does_not_touch_metadata_when_entity_actually_exists(): void
    {
        $store = Store::factory()->create(['code' => 'LIVE_SHOP']);
        $category = Category::factory()->forStore($store)->create(['name' => 'Existing']);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'admin-cat-200',
            'source' => 'bagisto',
            'shop_code' => 'LIVE_SHOP',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $result = $this->consumer->processEvent($this->payload([
            'event_type' => 'updated',
            'id' => 'admin-cat-200',
            'shop_code' => 'LIVE_SHOP',
            'title' => 'Updated name',
        ]));

        $this->assertSame('ack', $result);
        $category->refresh();
        $this->assertSame('Updated name', $category->name);

        // Метадата не дублировалась
        $count = EventSyncMetadata::where('entity_type', 'categories')
            ->where('external_id', 'admin-cat-200')
            ->where('source', 'bagisto')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_rethrows_unrelated_runtime_exception(): void
    {
        $this->expectException(\Comrades\RabbitMQEvents\Exceptions\InvalidEventException::class);

        // Payload без обязательного поля 'id' триггерит InvalidEventException —
        // не наша зона ответственности, должен прорасти наверх.
        $this->consumer->processEvent([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'source' => 'bagisto',
            'shop_code' => 'ANY_SHOP',
            'title' => 'No id',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'cat_'.Str::random(5),
            'source' => 'bagisto',
            'shop_code' => 'TEST_'.Str::random(3),
            'title' => 'Тест',
            'active' => true,
        ], $overrides);
    }
}
