<?php

declare(strict_types=1);

namespace App\Transformers;

use App\Models\OneCExchangeStatus;
use App\Models\OneCProblemProduct;
use App\Support\EventDateTime;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OneCExchangeStatusEventTransformer implements EventTransformer
{
    public function toEvent(Model $model, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $model->id,
            'source' => config('rabbitmq-events.source'),
        ];
    }

    public function fromEvent(array $data): array
    {
        return [
            'data_type' => $data['data_type'],
            'success' => $data['success'] ?? true,
            'error' => $data['error'] ?? null,
            'records_count' => $data['records_count'] ?? 0,
            'duration_seconds' => max(0, (int) ($data['duration_seconds'] ?? 0)),
            'counters' => $data['counters'] ?? [],
            'warnings' => $data['warnings'] ?? [],
            // Метки 1С приходят в красноярской зоне с явным офсетом — без
            // приведения к таймзоне приложения в колонку ляжет +4 часа (MID-272)
            'last_exchange_at' => EventDateTime::fromPayload($data['finished_at'] ?? $data['event_timestamp'] ?? null) ?? now(),
            'shop_id' => $data['shop_id'] ?? null,
        ];
    }

    public function validate(array $data): bool
    {
        return isset($data['event_id'], $data['data_type']);
    }

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $query = OneCExchangeStatus::where('data_type', $eventData['data_type']);
        if (isset($eventData['shop_id'])) {
            $query->where('shop_id', $eventData['shop_id']);
        }
        return $query->first();
    }

    /**
     * Хуки вызываются пакетом RabbitMQ через method_exists. Снимок проблемных
     * товаров обновляем и при создании, и при обновлении статуса обмена.
     */
    public function afterCreate(Model $model, array $eventData): void
    {
        $this->syncProblemProducts($model, $eventData);
    }

    public function afterUpdate(Model $model, array $eventData): void
    {
        $this->syncProblemProducts($model, $eventData);
    }

    /**
     * Replace-snapshot проблемных товаров 1С (FOODLINK-11039).
     *
     * problem_product_codes — полный набор кодов, проверённых для этого data_type
     * (включая нулевые) → удаляем старые строки по этим кодам и магазину, затем
     * вставляем актуальные из problem_products. Так «починенный» код (стал 0)
     * корректно вычищается.
     */
    private function syncProblemProducts(Model $model, array $eventData): void
    {
        $shopId = $model->shop_id ?? ($eventData['shop_id'] ?? null);

        $codes = $eventData['problem_product_codes']
            ?? array_keys($eventData['problem_products'] ?? []);

        if (empty($codes)) {
            // data_type без товарных кодов (rests/orders) — синкать нечего
            return;
        }

        $problemProducts = $eventData['problem_products'] ?? [];

        DB::transaction(function () use ($shopId, $codes, $problemProducts) {
            $delete = OneCProblemProduct::query()->whereIn('code', $codes);
            $shopId === null ? $delete->whereNull('shop_id') : $delete->where('shop_id', $shopId);
            $delete->delete();

            $now = now();

            foreach ($problemProducts as $code => $products) {
                if (!is_array($products) || empty($products)) {
                    continue;
                }

                $rows = [];
                foreach ($products as $p) {
                    $rows[] = [
                        'shop_id' => $shopId,
                        'code' => (string) $code,
                        'external_id' => $this->trim($p['external_id'] ?? null, 64),
                        'sku' => $this->trim($p['sku'] ?? null, 191),
                        'name' => $this->trim($p['name'] ?? null, 500),
                        'barcode' => $this->trim($p['barcode'] ?? null, 191),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    OneCProblemProduct::insert($chunk);
                }
            }
        });
    }

    /**
     * Обрезка под длину колонки. mb_substr — значения могут быть кириллицей,
     * byte-обрезка сломала бы UTF-8.
     */
    private function trim(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
