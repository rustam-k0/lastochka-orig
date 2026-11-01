<?php

declare(strict_types=1);

namespace App\RabbitMQ\Debug;

use Illuminate\Database\Eloquent\Model;

final class RabbitDebugService
{
    private ?int $currentLogId = null;

    private ?float $startTime = null;

    /**
     * Проверить, включено ли логирование.
     */
    public function isEnabled(): bool
    {
        return (bool) config('rabbit-debug.enabled', false);
    }

    /**
     * Логировать входящее сообщение (начало обработки).
     *
     * @param  array<string, mixed>  $payload
     */
    public function logInbound(
        string $routingKey,
        array $payload,
        ?string $queueName = null,
    ): ?int {
        if (! $this->isEnabled()) {
            return null;
        }

        $this->startTime = microtime(true);

        $data = [
            'direction' => 'inbound',
            'routing_key' => $routingKey,
            'queue_name' => $queueName,
            'payload' => $this->sanitizePayload($payload),
            'external_id' => $payload['id'] ?? null,
            'status' => 'pending',
        ];

        $this->currentLogId = $this->createLog($data);

        return $this->currentLogId;
    }

    /**
     * Логировать исходящее сообщение.
     *
     * @param  array<string, mixed>  $payload
     */
    public function logOutbound(
        string $routingKey,
        array $payload,
        ?string $entityType = null,
        ?int $entityId = null,
    ): ?int {
        if (! $this->isEnabled()) {
            return null;
        }

        $data = [
            'direction' => 'outbound',
            'routing_key' => $routingKey,
            'payload' => $this->sanitizePayload($payload),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'external_id' => $payload['id'] ?? null,
            'status' => 'processed',
        ];

        return $this->createLog($data);
    }

    /**
     * Пометить сообщение как успешно обработанное.
     *
     * @param  array<string, mixed>|null  $dbSnapshot
     */
    public function markProcessed(
        ?int $logId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $dbSnapshot = null,
    ): void {
        $logId = $logId ?? $this->currentLogId;

        if (! $logId || ! $this->isEnabled()) {
            return;
        }

        $processingTime = $this->startTime
            ? (int) round((microtime(true) - $this->startTime) * 1000)
            : null;

        RabbitMessageLog::where('id', $logId)->update([
            'status' => 'processed',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'db_snapshot' => $dbSnapshot,
            'processing_time_ms' => $processingTime,
        ]);

        $this->reset();
    }

    /**
     * Пометить сообщение как неудачно обработанное.
     */
    public function markFailed(
        \Throwable $exception,
        ?int $logId = null,
    ): void {
        $logId = $logId ?? $this->currentLogId;

        if (! $logId || ! $this->isEnabled()) {
            return;
        }

        $processingTime = $this->startTime
            ? (int) round((microtime(true) - $this->startTime) * 1000)
            : null;

        RabbitMessageLog::where('id', $logId)->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString(),
            'processing_time_ms' => $processingTime,
        ]);

        $this->reset();
    }

    /**
     * Создать db_snapshot из модели.
     *
     * @return array<string, mixed>
     */
    public function createDbSnapshot(Model $model): array
    {
        return [
            'table' => $model->getTable(),
            'id' => $model->getKey(),
            'data' => $model->toArray(),
        ];
    }

    /**
     * Получить текущий ID лога.
     */
    public function getCurrentLogId(): ?int
    {
        return $this->currentLogId;
    }

    /**
     * Сбросить состояние.
     */
    private function reset(): void
    {
        $this->currentLogId = null;
        $this->startTime = null;
    }

    /**
     * Создать запись лога (sync или async).
     *
     * @param  array<string, mixed>  $data
     */
    private function createLog(array $data): int
    {
        if (config('rabbit-debug.async_logging', true)) {
            // Для async нужно сначала создать запись синхронно, чтобы получить ID
            // Иначе не сможем обновить статус позже
            $log = RabbitMessageLog::create($data);

            return $log->id;
        }

        $log = RabbitMessageLog::create($data);

        return $log->id;
    }

    /**
     * Скрыть sensitive поля в payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $hiddenFields = config('rabbit-debug.hidden_fields', []);

        return $this->sanitizeRecursive($payload, $hiddenFields);
    }

    /**
     * Рекурсивно скрыть sensitive поля.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string>  $hiddenFields
     * @return array<string, mixed>
     */
    private function sanitizeRecursive(array $data, array $hiddenFields): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), array_map('strtolower', $hiddenFields), true)) {
                $data[$key] = '[HIDDEN]';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitizeRecursive($value, $hiddenFields);
            }
        }

        return $data;
    }
}
