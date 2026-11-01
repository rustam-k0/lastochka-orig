<?php

namespace App\Services\Outbox;

use App\Models\System\EventOutbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OutboxService
{
    public function dispatch(string $entityType, string $eventType, array $payload, ?string $entityId = null): EventOutbox
    {
        $eventId = (string) Str::uuid();
        $timestamp = now()->timestamp;
        $eventSource = config('rabbitmq-events.source', 'retailbridge');

        // Нормализуем event_type: убираем префикс сущности если есть
        // "order.updated" -> "updated", "updated" -> "updated"
        $normalizedEventType = $this->normalizeEventType($eventType, $entityType);

        // routing_key всегда включает entity_type для роутинга в RabbitMQ
        $routingKey = "{$entityType}.{$normalizedEventType}";

        $payload = array_merge($payload, [
            'event_id' => $eventId,
            'event_type' => $normalizedEventType,
            'event_source' => $eventSource,
            'event_timestamp' => $timestamp,
        ]);

        Log::info("Dispatching outbox event: {$routingKey}", [
            'event_id' => $eventId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_type' => $normalizedEventType,
            'routing_key' => $routingKey,
        ]);

        return EventOutbox::create([
            'event_id' => $eventId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_type' => $normalizedEventType,
            'routing_key' => $routingKey,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    /**
     * Нормализует event_type, убирая префикс сущности.
     *
     * @param string $eventType Исходный event_type (может быть "order.updated" или "updated")
     * @param string $entityType Тип сущности для проверки префикса
     * @return string Нормализованный event_type без префикса ("updated", "created", "deleted" и т.д.)
     */
    protected function normalizeEventType(string $eventType, string $entityType): string
    {
        $prefix = $entityType . '.';

        if (str_starts_with($eventType, $prefix)) {
            return substr($eventType, strlen($prefix));
        }

        return $eventType;
    }
}
