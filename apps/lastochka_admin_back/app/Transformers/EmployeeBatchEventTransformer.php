<?php

declare(strict_types=1);

namespace App\Transformers;

use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;

/**
 * Validate-only трансформер пачки сотрудников из 1С (employee.batch_updated, MID-306).
 * Обработка — App\RabbitMQ\Handlers\EmployeeBatchHandler: EventConsumer роутит на handler
 * после validate(). У конверта нет верхнеуровневого id и shop_code.
 */
class EmployeeBatchEventTransformer implements EventTransformer
{
  use ValidatesEventData;

  protected array $requiredFields = ['event_id', 'event_type', 'source', 'items'];

  /**
   * batch_updated — кастомный тип: без этой записи трейт требовал бы id.
   */
  protected array $customEventRequiredFields = [
    'batch_updated' => ['event_id', 'event_type', 'source', 'items'],
  ];

  public function toEvent(Model $model, ?object $relatedModel = null, ?string $eventId = null): array
  {
    return [];
  }

  public function fromEvent(array $eventData): array
  {
    return [];
  }
}
