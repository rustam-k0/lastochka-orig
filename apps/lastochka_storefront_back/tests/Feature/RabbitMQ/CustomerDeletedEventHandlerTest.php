<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CustomerDeletedEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('customers', config('rabbitmq-events.entities.customers'));
    }

    public function test_customer_deleted_event_soft_deletes_local_user(): void
    {
        $user = User::factory()->create([
            'phone' => '+79001234567',
        ]);

        $externalId = '12345';

        EventSyncMetadata::create([
            'entity_type' => 'customers',
            'entity_id' => $user->id,
            'external_id' => $externalId,
            'source' => 'admin',
            'shop_code' => null,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'deleted',
            'event_timestamp' => now()->toIso8601String(),
            'id' => $externalId,
            'source' => 'admin',
            'shop_code' => null,
            'phone' => '+79001234567',
            'deleted_at' => now()->toIso8601String(),
        ];

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_customer_deleted_event_is_idempotent_on_duplicate_event_id(): void
    {
        $user = User::factory()->create([
            'phone' => '+79002222222',
        ]);

        $eventId = Str::uuid()->toString();
        $externalId = '777';

        EventSyncMetadata::create([
            'entity_type' => 'customers',
            'entity_id' => $user->id,
            'external_id' => $externalId,
            'source' => 'admin',
            'shop_code' => null,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = [
            'event_id' => $eventId,
            'event_type' => 'deleted',
            'event_timestamp' => now()->toIso8601String(),
            'id' => $externalId,
            'source' => 'admin',
            'shop_code' => null,
            'phone' => '+79002222222',
            'deleted_at' => now()->toIso8601String(),
        ];

        // Первый раз — обработка приводит к soft delete.
        $this->consumer->processEvent($eventData);
        $this->assertSoftDeleted('users', ['id' => $user->id]);

        // Повторная обработка того же event_id — должна быть идемпотентной.
        $result = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result);
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_customer_deleted_event_skipped_when_user_never_existed(): void
    {
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'deleted',
            'event_timestamp' => now()->toIso8601String(),
            'id' => '99999',
            'source' => 'admin',
            'shop_code' => null,
            'phone' => '+79003333333',
            'deleted_at' => now()->toIso8601String(),
        ];

        $result = $this->consumer->processEvent($eventData);

        // Consumer завершает обработку даже если сущность не существует локально —
        // delete-событие не должно воскресить того, кого мы никогда не синхронизировали.
        $this->assertEquals('ack', $result);
        $this->assertDatabaseMissing('users', ['phone' => '+79003333333']);
    }
}
