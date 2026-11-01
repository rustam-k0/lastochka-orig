<?php

declare(strict_types=1);

namespace App\RabbitMQ\Debug;

use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Support\Facades\Log;

/**
 * EventConsumer с логированием в RabbitDebugService.
 *
 * Наследник, а не декоратор: так его отдаёт ConsumeEventsCommand::makeConsumer()
 * циклу пакета (ConsumeEvents::handleMessage, stale_after_retry через
 * lastStaleVersion()), и витрина живёт на общем цикле пакета, в том числе в
 * общем процессе rabbitmq:consume-all.
 *
 * Кроме логирования — лечит «orphan metadata с непустым entity_id»:
 * EventConsumer пакета кидает RuntimeException("Entity not found: <id>")
 * когда metadata указывает на физически удалённую запись (force delete,
 * ручная чистка БД, миграционный wipe). Без этого override любой такой
 * payload навечно блокируется в DLQ.
 *
 * Recover: ловим именно эту ошибку, удаляем «битую» metadata-запись,
 * один раз ре-обрабатываем тот же payload — пакет создаст entity заново
 * через INSERT-first путь.
 */
final class LoggingEventConsumer extends EventConsumer
{
    private RabbitDebugService $debugService;

    private ?string $queueName;

    public function __construct(
        string $entityType,
        array $entityConfig,
        ?string $queueName = null,
    ) {
        parent::__construct($entityType, $entityConfig);
        $this->debugService = app(RabbitDebugService::class);
        $this->queueName = $queueName;
    }

    /**
     * Обработать событие с логированием.
     *
     * Цикл пакета зовёт processEvent($eventData) без ключа: ключ события лежит
     * в $eventData['_routing_key'] (ConsumeEvents::handleMessage кладёт туда
     * x-original-routing-key у адресного ретрая или ключ доставки).
     *
     * @param  array<string, mixed>  $eventData
     */
    public function processEvent(array $eventData, ?string $routingKey = null): string
    {
        $routingKey = $routingKey ?? $this->resolveRoutingKey($eventData);

        // 1. Логируем начало обработки
        $logId = $this->debugService->logInbound(
            routingKey: $routingKey,
            payload: $eventData,
            queueName: $this->queueName,
        );

        try {
            return $this->processWithOrphanRecover($eventData, $logId);
        } catch (\Throwable $e) {
            // При ошибке — помечаем как failed (после всех попыток recover)
            $this->debugService->markFailed($e, $logId);

            throw $e;
        }
    }

    /**
     * Вызов обработки пакета с одной попыткой recover'а от orphan metadata.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processWithOrphanRecover(array $eventData, ?int $logId): string
    {
        try {
            $result = parent::processEvent($eventData);
            if ($result === 'ack') {
                $this->markAsProcessed($logId, $eventData);
            }

            return $result;
        } catch (\RuntimeException $e) {
            if (! $this->tryRecoverOrphanMetadata($e, $eventData)) {
                throw $e;
            }
        }

        // Retry один раз после recover'а. Если опять упадёт — наверх.
        $result = parent::processEvent($eventData);
        if ($result === 'ack') {
            $this->markAsProcessed($logId, $eventData);
        }

        return $result;
    }

    /**
     * Если исключение — про orphan metadata, удаляем её и сигналим caller'у
     * сделать retry. Sanity-check: запись действительно отсутствует в локальной БД.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function tryRecoverOrphanMetadata(\RuntimeException $e, array $eventData): bool
    {
        if (! preg_match('/^Entity not found: (\d+)$/', $e->getMessage(), $m)) {
            return false;
        }
        $orphanEntityId = (int) $m[1];

        $externalId = $eventData['id'] ?? null;
        $source = $eventData['source'] ?? null;
        if ($externalId === null || $source === null) {
            return false;
        }

        $modelClass = config("rabbitmq-events.entities.{$this->entityType}.model");
        if (! $modelClass) {
            return false;
        }
        $query = $modelClass::query();
        if (method_exists($modelClass, 'withTrashed')) {
            $query = $query->withTrashed();
        }
        if ($query->whereKey($orphanEntityId)->exists()) {
            // Запись на самом деле есть — не наш случай, не трогаем metadata.
            return false;
        }

        $metadata = EventSyncMetadata::query()
            ->where('entity_type', $this->entityType)
            ->where('external_id', (string) $externalId)
            ->where('source', (string) $source)
            ->where('entity_id', $orphanEntityId)
            ->first();
        if ($metadata === null) {
            return false;
        }

        $metadata->delete();

        Log::warning('Orphan metadata cleaned — entity_id pointed to missing row', [
            'entity_type' => $this->entityType,
            'external_id' => $externalId,
            'source' => $source,
            'missing_entity_id' => $orphanEntityId,
            'event_id' => $eventData['event_id'] ?? null,
        ]);

        return true;
    }

    /**
     * Пометить как успешно обработанное с db_snapshot.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function markAsProcessed(?int $logId, array $eventData): void
    {
        if (! $logId) {
            return;
        }

        $entityId = $this->resolveEntityId($eventData);
        $dbSnapshot = null;

        if ($entityId) {
            $dbSnapshot = $this->createDbSnapshot($entityId);
        }

        $this->debugService->markProcessed(
            logId: $logId,
            entityType: $this->entityType,
            entityId: $entityId,
            dbSnapshot: $dbSnapshot,
        );
    }

    /**
     * Найти entity_id по данным события.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function resolveEntityId(array $eventData): ?int
    {
        $externalId = $eventData['id'] ?? null;
        $source = $eventData['source'] ?? null;

        if (! $externalId || ! $source) {
            return null;
        }

        // Ищем в metadata
        return EventSyncMetadata::query()
            ->where('entity_type', $this->entityType)
            ->where('external_id', $externalId)
            ->where('source', $source)
            ->value('entity_id');
    }

    /**
     * Создать db_snapshot из модели.
     *
     * @return array<string, mixed>|null
     */
    private function createDbSnapshot(int $entityId): ?array
    {
        $modelClass = config("rabbitmq-events.entities.{$this->entityType}.model");

        if (! $modelClass) {
            return null;
        }

        $query = $modelClass::query();

        // Поддержка soft deletes
        if (method_exists($modelClass, 'withTrashed')) {
            $query = $query->withTrashed();
        }

        $model = $query->find($entityId);

        if (! $model) {
            return null;
        }

        return $this->debugService->createDbSnapshot($model);
    }

    /**
     * Ключ события для лога: из _routing_key пакета, иначе по префиксу сущности.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function resolveRoutingKey(array $eventData): string
    {
        $fromPackage = $eventData['_routing_key'] ?? null;

        if (is_string($fromPackage) && $fromPackage !== '') {
            return $fromPackage;
        }

        return $this->buildRoutingKey($eventData);
    }

    /**
     * Построить routing_key из данных события.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function buildRoutingKey(array $eventData): string
    {
        $prefix = config("rabbitmq-events.entities.{$this->entityType}.routing_key_prefix", $this->entityType);
        $eventType = $eventData['event_type'] ?? 'unknown';

        return "{$prefix}.{$eventType}";
    }
}
