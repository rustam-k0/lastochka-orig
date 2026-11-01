<?php

declare(strict_types=1);

namespace App\RabbitMQ\Debug;

use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Database\Eloquent\Model;

/**
 * Обёртка над CustomEventPublisher с логированием в RabbitMQ Debug Dashboard.
 *
 * Использование:
 * ```php
 * LoggingEventPublisher::dispatch('orders', $order, 'order_created', [], $store->code);
 * ```
 */
final class LoggingEventPublisher
{
    /**
     * Publish a custom event with debug logging.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function dispatch(
        string $entityType,
        Model $model,
        string $eventType,
        array $payload = [],
        ?string $shopCode = null,
    ): string {
        $debugService = app(RabbitDebugService::class);

        // Формируем routing key
        $routingKeyPrefix = config("rabbitmq-events.entities.{$entityType}.routing_key_prefix", $entityType);
        $routingKey = "{$routingKeyPrefix}.{$eventType}";

        // Собираем payload для логирования (базовая структура)
        $logPayload = array_merge([
            'event_type' => $eventType,
            'id' => $model->getKey(),
            'entity_type' => $entityType,
            'shop_code' => $shopCode ?? config('rabbitmq-events.shop_code', 'default'),
        ], $payload);

        // Логируем исходящее сообщение
        $debugService->logOutbound(
            routingKey: $routingKey,
            payload: $logPayload,
            entityType: $entityType,
            entityId: $model->getKey(),
        );

        // Вызываем оригинальный publisher
        return CustomEventPublisher::dispatch($entityType, $model, $eventType, $payload, $shopCode);
    }

    /**
     * Publish to all related shops with debug logging.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string>
     */
    public static function dispatchToAllShops(
        string $entityType,
        Model $model,
        string $eventType,
        array $payload = [],
    ): array {
        $debugService = app(RabbitDebugService::class);

        $routingKeyPrefix = config("rabbitmq-events.entities.{$entityType}.routing_key_prefix", $entityType);
        $routingKey = "{$routingKeyPrefix}.{$eventType}";

        $logPayload = array_merge([
            'event_type' => $eventType,
            'id' => $model->getKey(),
            'entity_type' => $entityType,
            'multi_shop' => true,
        ], $payload);

        $debugService->logOutbound(
            routingKey: $routingKey,
            payload: $logPayload,
            entityType: $entityType,
            entityId: $model->getKey(),
        );

        return CustomEventPublisher::dispatchToAllShops($entityType, $model, $eventType, $payload);
    }

    /**
     * Publish multiple events with debug logging.
     *
     * @param  array<array<string, mixed>>  $events
     * @return array<string>
     */
    public static function dispatchMany(array $events): array
    {
        $debugService = app(RabbitDebugService::class);

        foreach ($events as $event) {
            $entityType = $event['entityType'] ?? $event[0] ?? null;
            $model = $event['model'] ?? $event[1] ?? null;
            $eventType = $event['eventType'] ?? $event[2] ?? null;
            $payload = $event['payload'] ?? $event[3] ?? [];
            $shopCode = $event['shopCode'] ?? $event[4] ?? null;

            if ($entityType && $model && $eventType) {
                $routingKeyPrefix = config("rabbitmq-events.entities.{$entityType}.routing_key_prefix", $entityType);
                $routingKey = "{$routingKeyPrefix}.{$eventType}";

                $logPayload = array_merge([
                    'event_type' => $eventType,
                    'id' => $model->getKey(),
                    'entity_type' => $entityType,
                    'shop_code' => $shopCode ?? config('rabbitmq-events.shop_code', 'default'),
                ], $payload);

                $debugService->logOutbound(
                    routingKey: $routingKey,
                    payload: $logPayload,
                    entityType: $entityType,
                    entityId: $model->getKey(),
                );
            }
        }

        return CustomEventPublisher::dispatchMany($events);
    }
}
