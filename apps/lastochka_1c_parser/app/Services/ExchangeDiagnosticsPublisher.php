<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExchangeLog;
use App\Models\Order;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExchangeDiagnosticsPublisher
{
    /**
     * Максимум «сырых» предупреждений импортёра в одном событии диагностики.
     *
     * На выгрузке без секции <Классификатор> 1С каждый товар порождает warning
     * «Категория не найдена…» — их бывают тысячи. Весь список в payload раздувал
     * событие `1c_exchange_status` и ронял outbox publisher (см. MAX_PER_CODE в
     * ProblemProductsCollector). Передаём первые N + сводку об усечении.
     */
    public const MAX_RAW_WARNINGS = 100;

    /**
     * Окно троттлинга «пустой» диагностики, секунды.
     *
     * Должно быть заметно меньше STALE_THRESHOLDS в admin_back (минимум 2 часа),
     * иначе пустые обмены перестанут обновлять last_exchange_at и админка
     * покажет «обмен протух», хотя 1С исправно на связи.
     */
    public const EMPTY_THROTTLE_SECONDS = 300;

    /**
     * Опубликовать диагностику после завершения обмена.
     *
     * @param ExchangeLog $log Запись лога обмена
     * @param array $result Результат из импортера (counters)
     */
    public static function publish(ExchangeLog $log, array $result = []): void
    {
        try {
            $dataType = $log->type;

            // 1С опрашивает /1c-exchange раз в 1-2 секунды, и почти все обмены
            // приходят пустыми («товаров 0», «нет заказов для экспорта»). Публиковать
            // на каждый такой опрос событие со снапшотом проблемных товаров (~55 КБ)
            // бессмысленно: 118 тыс. событий, ~3 ГБ в event_outbox и ~20 ГБ бинлогов
            // за сутки — именно так 2026-08-31 закончилось место на проде и все
            // бэкенды отдавали 500 (GlitchTip issue 802). Содержательные обмены,
            // ошибки и предупреждения публикуются всегда.
            if (self::isEmptyExchange($log, $dataType, $result) && self::throttled($dataType)) {
                return;
            }

            $entries = ProblemProductsCollector::collect($dataType);
            $warnings = self::collectWarnings($dataType, $result, $entries);

            $payload = [
                'event_id' => Str::uuid()->toString(),
                'event_timestamp' => now()->toIso8601String(),
                'event_source' => config('rabbitmq-events.source', '1c'),
                'source' => config('rabbitmq-events.source', '1c'),
                'id' => (string) $log->id,
                'shop_code' => self::getCurrentShopCode(),
                'shop_id' => self::getCurrentShopId(),
                'data_type' => $dataType,
                'success' => $log->status === ExchangeLog::STATUS_SUCCESS,
                'error' => $log->status === ExchangeLog::STATUS_ERROR ? $log->message : null,
                'records_count' => $log->records_count,
                'duration_seconds' => $log->duration,
                'counters' => self::buildCounters($dataType, $result),
                'warnings' => $warnings,
                'problem_products' => self::buildProblemProducts($entries),
                'problem_product_codes' => ProblemProductsCollector::codesForDataType($dataType),
                'finished_at' => $log->finished_at?->toIso8601String(),
            ];

            EventOutbox::create([
                'entity_type' => '1c_exchange_status',
                'entity_id' => $log->id,
                'event_type' => 'updated',
                'event_id' => $payload['event_id'],
                'routing_key' => '1c_exchange.updated',
                'payload' => $payload,
            ]);

            self::markPublished($dataType);

            Log::channel('1c-exchange')->debug('Diagnostics published', [
                'data_type' => $dataType,
                'success' => $payload['success'],
            ]);
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->warning('Failed to publish diagnostics', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обмен, который нечего показывать в админке: успешный, без записей,
     * без предупреждений импортёра и с нулевыми счётчиками.
     *
     * Ошибки и любые ненулевые счётчики (например `pending_count` у orders_export —
     * заказы висят неотправленными) пустыми НЕ считаются и публикуются всегда.
     */
    protected static function isEmptyExchange(ExchangeLog $log, string $dataType, array $result): bool
    {
        if ($log->status !== ExchangeLog::STATUS_SUCCESS) {
            return false;
        }

        if ((int) $log->records_count > 0) {
            return false;
        }

        if (!empty($result['warnings'])) {
            return false;
        }

        foreach (self::buildCounters($dataType, $result) as $key => $value) {
            if ($key === 'warnings') {
                continue;
            }

            if (is_numeric($value) ? (float) $value !== 0.0 : !empty($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Публиковали ли пустую диагностику этого типа недавно.
     *
     * Кеш недоступен — считаем, что нет: молчащая диагностика хуже лишнего события.
     */
    protected static function throttled(string $dataType): bool
    {
        try {
            return Cache::has(self::throttleKey($dataType));
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function markPublished(string $dataType): void
    {
        try {
            Cache::put(self::throttleKey($dataType), now()->timestamp, self::EMPTY_THROTTLE_SECONDS);
        } catch (\Throwable $e) {
            // Троттлинг — оптимизация, а не инвариант: сбой кеша не должен ломать обмен.
        }
    }

    /**
     * Ключ окна: магазин + тип данных, чтобы обмены разных магазинов
     * и разных типов не глушили друг друга.
     */
    protected static function throttleKey(string $dataType): string
    {
        return sprintf('1c:diagnostics:empty:%s:%s', self::getCurrentShopCode(), $dataType);
    }

    protected static function getCurrentShopCode(): string
    {
        try {
            return app('current_shop_code');
        } catch (\Throwable $e) {
            return config('rabbitmq-events.shop_code', 'aladdin');
        }
    }

    protected static function getCurrentShopId(): ?int
    {
        try {
            return app('current_shop_id');
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function buildCounters(string $dataType, array $result): array
    {
        return match ($dataType) {
            ExchangeLog::TYPE_CATALOG_IMPORT => [
                'categories_count' => $result['categories'] ?? 0,
                'products_count' => $result['products'] ?? 0,
                'images_count' => $result['images'] ?? 0,
                'skipped_whitelist' => $result['skipped_whitelist'] ?? 0,
            ],
            ExchangeLog::TYPE_OFFERS_IMPORT => [
                'prices_count' => $result['prices'] ?? 0,
                'stocks_count' => $result['stocks'] ?? 0,
                'skipped_count' => $result['skipped'] ?? 0,
            ],
            ExchangeLog::TYPE_RESTS_IMPORT => [
                'stocks_count' => $result['stocks'] ?? 0,
                'skipped_count' => $result['skipped'] ?? 0,
            ],
            ExchangeLog::TYPE_ORDERS_IMPORT => [
                'processed_count' => $result['processed'] ?? 0,
            ],
            ExchangeLog::TYPE_ORDERS_EXPORT => [
                'exported_count' => $result['exported'] ?? 0,
                'pending_count' => $result['pending'] ?? 0,
            ],
            default => $result,
        };
    }

    /**
     * @param array<int, array{code:string, count:int, message:string}> $entries
     */
    protected static function collectWarnings(string $dataType, array $result, array $entries = []): array
    {
        $warnings = [];

        // Warnings из результата импортера. Усекаем до MAX_RAW_WARNINGS, чтобы
        // тысячи «Категория не найдена…» не раздували payload события.
        if (!empty($result['warnings'])) {
            $rawWarnings = array_values($result['warnings']);
            $rawTotal = count($rawWarnings);

            foreach (array_slice($rawWarnings, 0, self::MAX_RAW_WARNINGS) as $w) {
                $warnings[] = is_string($w) ? ['message' => $w] : $w;
            }

            if ($rawTotal > self::MAX_RAW_WARNINGS) {
                $warnings[] = [
                    'code' => 'warnings_truncated',
                    'count' => $rawTotal,
                    'message' => sprintf(
                        '…и ещё %d предупреждений (показаны первые %d из %d)',
                        $rawTotal - self::MAX_RAW_WARNINGS,
                        self::MAX_RAW_WARNINGS,
                        $rawTotal
                    ),
                ];
            }
        }

        // Товарные реквизиты (каталог/офферы) — из коллектора, чтобы count
        // и список товаров считались одним проходом и не разъезжались.
        foreach ($entries as $entry) {
            $warnings[] = [
                'code' => $entry['code'],
                'count' => $entry['count'],
                'message' => $entry['message'],
            ];
        }

        // Остатки: склады без warehouse_id — это про Stock, не про товар,
        // отдельной страницы списка нет (коллектор сюда не лезет).
        if ($dataType === ExchangeLog::TYPE_RESTS_IMPORT) {
            self::checkRestsRequisites($warnings);
        }

        return $warnings;
    }

    /**
     * Списки проблемных товаров для admin_back: code → [{external_id, sku, name, barcode}].
     *
     * @param array<int, array{code:string, products:array}> $entries
     * @return array<string, array>
     */
    protected static function buildProblemProducts(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $out[$entry['code']] = $entry['products'];
        }

        return $out;
    }

    /**
     * Остатки: Ид товара (существует), Склад Ид, Количество
     * Проверяем stocks без warehouse_id
     */
    protected static function checkRestsRequisites(array &$warnings): void
    {
        $stocksWithoutWarehouse = \App\Models\Stock::where(function ($q) {
            $q->whereNull('warehouse_id')->orWhere('warehouse_id', '');
        })->count();
        if ($stocksWithoutWarehouse > 0) {
            $warnings[] = ['code' => 'missing_warehouse_id', 'count' => $stocksWithoutWarehouse, 'message' => "{$stocksWithoutWarehouse} остатков без ID склада"];
        }
    }
}
