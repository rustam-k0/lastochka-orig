<?php

declare(strict_types=1);

namespace App\RabbitMQ\Handlers;

use App\Services\EventNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Приёмник всех событий шины в журнал analytics_events.
 *
 * Инварианты:
 *  - handler ВСЕГДА возвращает 'ack' — журнал не имеет права ронять очередь;
 *  - битое событие пишется с event_type='_unparsed' (сырьё + текст ошибки);
 *  - дедуп по (event_id, occurred_at) через insertOrIgnore (ON CONFLICT DO NOTHING).
 */
final class AnalyticsEventHandler
{
    public function __construct(private readonly EventNormalizer $normalizer) {}

    /**
     * @param  array<string, mixed>  $eventData  Конверт события (+ metadata.routing_key)
     */
    public function handle(array $eventData): string
    {
        try {
            $row = $this->normalizer->normalize($eventData);

            if ($row['event_type'] === 'identity.linked') {
                $this->linkIdentity($eventData);
            }

            $this->insert($row);
        } catch (\Throwable $e) {
            Log::warning('Analytics event failed to normalize, stored as _unparsed', [
                'error' => $e->getMessage(),
                'event_id' => $eventData['event_id'] ?? null,
            ]);

            $this->insertUnparsed($eventData, $e);
        }

        return 'ack';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insert(array $row): void
    {
        $row['payload'] = $this->encodePayload($row['payload']);
        // Строка со смещением: naive-timestamp интерпретировался бы
        // session-таймзоной Postgres (источник сдвига -3ч на dev).
        $row['occurred_at'] = $row['occurred_at']->utc()->format('Y-m-d H:i:sP');

        DB::table('analytics_events')->insertOrIgnore($row);
    }

    private function insertUnparsed(array $eventData, \Throwable $e): void
    {
        try {
            DB::table('analytics_events')->insertOrIgnore([
                'event_id' => (string) ($eventData['event_id'] ?? Str::uuid()),
                'event_type' => '_unparsed',
                'occurred_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:sP'),
                'source' => (string) ($eventData['event_source'] ?? $eventData['source'] ?? 'unknown'),
                'payload' => $this->encodePayload([
                    'error' => $e->getMessage(),
                    'raw' => $eventData,
                ]),
            ]);
        } catch (\Throwable $inner) {
            // Последний рубеж: журналирование не должно уронить консьюмер.
            Log::error('Analytics _unparsed insert failed', ['error' => $inner->getMessage()]);
        }
    }

    private function linkIdentity(array $eventData): void
    {
        $anon = $eventData['anon_client_id'] ?? null;
        $userId = $eventData['user_id'] ?? null;

        if (! is_scalar($anon) || ! is_scalar($userId) || (string) $anon === '' || (string) $userId === '') {
            return;
        }

        DB::table('client_identity_links')->insertOrIgnore([
            'anon_client_id' => (string) $anon,
            'user_id' => (string) $userId,
            'linked_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * JSON_INVALID_UTF8_SUBSTITUTE: битые байты в событии не должны валить
     * вставку (json_encode(false) → пустой payload → потеря данных).
     */
    private function encodePayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? '{}' : $encoded;
    }
}
