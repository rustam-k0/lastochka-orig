<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Нормализация конверта события шины в строку analytics_events.
 *
 * Контракт полей — #11642/#11596. Полный исходный конверт всегда
 * сохраняется в payload: нормализация может ошибаться, сырые данные — нет.
 */
class EventNormalizer
{
    /**
     * @param  array<string, mixed>  $eventData  Конверт события из RabbitMQ
     * @return array<string, mixed> Атрибуты строки analytics_events (occurred_at — CarbonImmutable, payload — array)
     */
    public function normalize(array $eventData): array
    {
        $routingKey = (string) ($eventData['metadata']['routing_key']
            ?? $eventData['_routing_key']
            ?? $eventData['event_type']
            ?? 'unknown');

        [$eventType, $source, $role] = $this->resolveTypeSourceRole($routingKey, $eventData);

        $rawEventId = $eventData['event_id'] ?? null;
        $eventId = (is_string($rawEventId) && Str::isUuid($rawEventId)) ? $rawEventId : (string) Str::uuid();

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => $this->resolveOccurredAt($eventData),
            'source' => $source,
            'correlation_id' => $this->stringOrNull($eventData['correlation_id'] ?? null)
                ?? $this->resolveOrderId($routingKey, $eventData),
            'session_id' => $this->stringOrNull($eventData['session_id'] ?? null),
            'store_id' => $this->stringOrNull($eventData['shop_code'] ?? null),
            'employee_id' => $this->resolveEmployeeId($routingKey, $eventData),
            'role' => $role,
            'order_id' => $this->resolveOrderId($routingKey, $eventData),
            'client_id' => $this->stringOrNull($eventData['client_id'] ?? null)
                ?? $this->resolveReviewClientId($routingKey, $eventData),
            'payload' => $eventData,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} [event_type, source, role]
     */
    private function resolveTypeSourceRole(string $routingKey, array $eventData): array
    {
        $envelopeSource = $this->stringOrNull($eventData['event_source'] ?? null)
            ?? $this->stringOrNull($eventData['source'] ?? null)
            ?? 'unknown';

        if (str_starts_with($routingKey, 'b2c.')) {
            return [Str::after($routingKey, 'b2c.'), 'b2c_app', 'client'];
        }

        if ($routingKey === 'order_stage.changed') {
            $role = ($eventData['actor_type'] ?? null) === 'staff' ? 'staff' : null;

            return ['order.stage_changed', $envelopeSource, $role];
        }

        if (str_starts_with($routingKey, 'picking.')) {
            return [$routingKey, $envelopeSource, 'picker'];
        }

        if (str_starts_with($routingKey, 'delivery.')) {
            return [$routingKey, $envelopeSource, 'courier'];
        }

        if (str_starts_with($routingKey, 'shift.')) {
            return [$routingKey, $envelopeSource, $this->stringOrNull($eventData['role'] ?? null)];
        }

        // #11601: оценка заказа клиентом (storefront order_review.*) — это
        // rating.received/updated контракта B2E. Атрибуция сборщику/курьеру
        // делается на этапе агрегации по order_id (storefront их не знает).
        if ($routingKey === 'order_review.created') {
            return ['rating.received', $envelopeSource, 'client'];
        }

        if ($routingKey === 'order_review.updated') {
            return ['rating.updated', $envelopeSource, 'client'];
        }

        return [$routingKey, $envelopeSource, null];
    }

    private function resolveOccurredAt(array $eventData): CarbonImmutable
    {
        $raw = $eventData['occurred_at'] ?? $eventData['event_timestamp'] ?? null;

        try {
            $ts = $raw !== null ? CarbonImmutable::parse($raw) : CarbonImmutable::now();
        } catch (\Throwable) {
            $ts = CarbonImmutable::now();
        }

        // Кривые клиентские часы не должны раскидывать журнал по вечности:
        // всё за пределами [-2 года, +1 день] пишем как now (сырое время в payload).
        if ($ts->greaterThan(CarbonImmutable::now()->addDay()) || $ts->lessThan(CarbonImmutable::now()->subYears(2))) {
            return CarbonImmutable::now();
        }

        return $ts;
    }

    private function resolveEmployeeId(string $routingKey, array $eventData): ?string
    {
        if (str_starts_with($routingKey, 'picking.')) {
            return $this->stringOrNull($eventData['picker_id'] ?? null);
        }

        if (str_starts_with($routingKey, 'delivery.')) {
            return $this->stringOrNull($eventData['courier_id'] ?? null);
        }

        if ($routingKey === 'order_stage.changed' && ($eventData['actor_type'] ?? null) === 'staff') {
            return $this->stringOrNull($eventData['actor_id'] ?? null);
        }

        if (str_starts_with($routingKey, 'shift.')) {
            return $this->stringOrNull($eventData['employee_id'] ?? null);
        }

        return null;
    }

    private function resolveOrderId(string $routingKey, array $eventData): ?string
    {
        $explicit = $this->stringOrNull($eventData['order_id'] ?? null);

        if ($explicit !== null) {
            return $explicit;
        }

        if (str_starts_with($routingKey, 'order.') || str_starts_with($routingKey, 'order_stage.')) {
            return $this->stringOrNull($eventData['id'] ?? null);
        }

        return null;
    }

    /**
     * У order_review.* клиент лежит в user_id (id юзера storefront) —
     * тот же идентификатор, что client_id авторизованных b2c-событий.
     */
    private function resolveReviewClientId(string $routingKey, array $eventData): ?string
    {
        if (! str_starts_with($routingKey, 'order_review.')) {
            return null;
        }

        return $this->stringOrNull($eventData['user_id'] ?? null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }
}
