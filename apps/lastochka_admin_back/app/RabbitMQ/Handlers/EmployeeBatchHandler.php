<?php

declare(strict_types=1);

namespace App\RabbitMQ\Handlers;

use App\Transformers\EmployeeEventTransformer;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Support\Facades\Log;

/**
 * Пачка сотрудников из 1С (employee.batch_updated, MID-306).
 *
 * Семантика: пачка эквивалентна N одиночным employee.updated с общим конвертом.
 * Битая позиция не роняет пачку, сводный лог одной строкой. Идемпотентность —
 * штатная пакетная по event_id конверта на каждого сотрудника: повтор сообщения
 * после ретрая не применяет позиции второй раз.
 */
class EmployeeBatchHandler
{
  public function __construct(private readonly EmployeeEventTransformer $employees)
  {
  }

  public function handle(array $eventData): string
  {
    if (($eventData['event_type'] ?? '') !== 'batch_updated') {
      Log::warning('1C employee batch skipped: event_type is not batch_updated', [
        'event_id' => $eventData['event_id'] ?? null,
        'event_type' => $eventData['event_type'] ?? null,
      ]);

      return 'ack';
    }

    if (!$this->employees->isOneCEmployeeEvent($eventData)) {
      Log::info('1C employee batch skipped: module onec_shops_master is off or source is not 1C', [
        'event_id' => $eventData['event_id'] ?? null,
      ]);

      return 'ack';
    }

    $raw = is_array($eventData['items'] ?? null) ? $eventData['items'] : [];
    $items = collect($raw)
      ->filter(static fn ($item) => is_array($item) && !empty($item['id']) && is_scalar($item['id']))
      ->unique(static fn (array $item) => (string) $item['id'])
      ->values();

    $stats = [
      'items_total' => count($raw),
      'processed' => 0,
      'skipped_invalid' => count($raw) - $items->count(),
      'errors' => 0,
    ];

    $consumer = new EventConsumer('employees', config('rabbitmq-events.entities.employees'));
    $envelope = array_filter([
      'event_id' => $eventData['event_id'] ?? null,
      'event_timestamp' => $eventData['event_timestamp'] ?? null,
      'source' => $eventData['source'] ?? null,
      'event_source' => $eventData['event_source'] ?? ($eventData['source'] ?? null),
      'event_type' => 'updated',
    ], static fn ($value) => $value !== null);

    foreach ($items as $item) {
      try {
        $consumer->processEvent(array_merge($item, $envelope));
        $stats['processed']++;
      } catch (\Throwable $e) {
        $stats['errors']++;
        Log::error('1C employee batch item failed', [
          'event_id' => $eventData['event_id'] ?? null,
          'external_id' => (string) $item['id'],
          'error' => $e->getMessage(),
        ]);
      }
    }

    Log::notice('1C employee batch processed', array_merge(['event_id' => $eventData['event_id'] ?? null], $stats));

    return 'ack';
  }
}
