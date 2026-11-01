<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\User;
use App\Services\ProductGroup\ProductViewRecorder;
use Carbon\CarbonImmutable;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Приём B2C поведенческих событий от мобилки (LASTOCHKI-JAMI-11659).
 *
 * Storefront ничего не хранит у себя: каждое валидное событие превращается
 * в запись event_outbox с routing key b2c.<event_type> — дальше шина и
 * lastochka_analytics_back.
 *
 * Контракт client_id (#11642): авторизованный = user_id (сервер подставляет
 * сам из токена), аноним = устойчивый UUID устройства. При авторизованном
 * запросе исходный анонимный UUID уезжает в payload.device_client_id и
 * дополнительно публикуется склейка b2c.identity.linked.
 */
final class AnalyticsIngestService
{
    public function __construct(
        private readonly ProductViewRecorder $productViewRecorder,
    ) {}

    /**
     * @param array<int, mixed> $events Сырые события из запроса
     * @return array{accepted: int, rejected: array<int, array{index: int, reason: string}>}
     */
    public function ingest(array $events, ?User $user, ?string $storeCode = null): array
    {
        $accepted = 0;
        $rejected = [];
        $identityPairs = [];

        foreach (array_values($events) as $index => $event) {
            $error = $this->validateEvent($event);

            if ($error !== null) {
                $rejected[] = ['index' => $index, 'reason' => $error];
                continue;
            }

            $deviceClientId = (string) $event['client_id'];
            $clientId = $user !== null ? (string) $user->id : $deviceClientId;

            $this->pushToOutbox(
                eventType: (string) $event['event_type'],
                payload: array_merge($event, [
                    'client_id' => $clientId,
                    'device_client_id' => $deviceClientId,
                ]),
                entityId: $user?->id ?? 0,
                storeCode: $storeCode,
            );

            if ($user !== null && $deviceClientId !== (string) $user->id) {
                $identityPairs[$deviceClientId] = true;
            }

            if ($user !== null && $event['event_type'] === 'product.viewed') {
                $this->recordProductView($user, $event);
            }

            $accepted++;
        }

        foreach (array_keys($identityPairs) as $anonId) {
            $this->publishIdentityLinked((string) $anonId, $user, $storeCode);
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * Просмотр карточки для автогруппы «Смотрел, но не купил». Ошибка записи
     * не роняет приём аналитики: событие уже в outbox, его примет шина.
     *
     * @param  array<string, mixed>  $event
     */
    private function recordProductView(User $user, array $event): void
    {
        try {
            $this->productViewRecorder->record($user, $event);
        } catch (\Throwable $e) {
            Log::warning('AnalyticsIngestService: product view not recorded', [
                'user_id' => $user->id,
                'product_id' => $event['product_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Склейка анонимного client_id с user_id — вызывается и из ingest,
     * и потенциально из других точек (например, оформление заказа).
     */
    public function publishIdentityLinked(string $anonClientId, User $user, ?string $storeCode = null): void
    {
        $this->pushToOutbox(
            eventType: 'identity.linked',
            payload: [
                'event_type' => 'identity.linked',
                'anon_client_id' => $anonClientId,
                'user_id' => (string) $user->id,
                'client_id' => (string) $user->id,
                'occurred_at' => now()->toIso8601String(),
            ],
            entityId: $user->id,
            storeCode: $storeCode,
        );
    }

    /**
     * Мягкая валидация одного события: причина отказа или null.
     */
    private function validateEvent(mixed $event): ?string
    {
        if (! is_array($event)) {
            return 'event must be an object';
        }

        $type = $event['event_type'] ?? null;

        if (! is_string($type) || ! in_array($type, config('analytics.allowed_events', []), true)) {
            return 'unknown event_type';
        }

        $clientId = $event['client_id'] ?? null;

        if (! is_string($clientId) || ! Str::isUuid($clientId)) {
            return 'client_id must be a UUID';
        }

        $sessionId = $event['session_id'] ?? null;

        if (! is_string($sessionId) || ! Str::isUuid($sessionId)) {
            return 'session_id must be a UUID';
        }

        $occurredAt = $event['occurred_at'] ?? null;

        if (! is_string($occurredAt)) {
            return 'occurred_at is required (ISO8601)';
        }

        try {
            $ts = CarbonImmutable::parse($occurredAt);
        } catch (\Throwable) {
            return 'occurred_at is not a valid timestamp';
        }

        if ($ts->lessThan(now()->subDays((int) config('analytics.max_event_age_days', 7)))) {
            return 'occurred_at is too old';
        }

        if ($ts->greaterThan(now()->addMinutes((int) config('analytics.max_future_skew_minutes', 5)))) {
            return 'occurred_at is in the future';
        }

        $encoded = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded === false || mb_strlen($encoded, '8bit') > (int) config('analytics.max_payload_bytes', 8192)) {
            return 'event payload is too large';
        }

        return null;
    }

    private function pushToOutbox(string $eventType, array $payload, int $entityId, ?string $storeCode): void
    {
        $eventId = (string) Str::uuid();
        $source = config('rabbitmq-events.source', 'storefront');

        EventOutbox::create([
            'entity_type' => 'b2c_events',
            'entity_id' => $entityId,
            'event_type' => $eventType,
            'event_id' => $eventId,
            'routing_key' => 'b2c.' . $eventType,
            'payload' => array_merge($payload, [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'event_timestamp' => now()->toIso8601String(),
                'id' => (string) $entityId,
                'source' => $source,
                'event_source' => $source,
                'shop_code' => $storeCode ?? config('rabbitmq-events.shop_code', 'default'),
            ]),
        ]);
    }
}
