<?php

namespace App\Transformers;

use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;

/**
 * Факт bonus_operation.created и отказ bonus_operation.rejected из storefront_back.
 *
 * Трансформер используется ТОЛЬКО для validate(): EventConsumer::processEvent()
 * вызывает его до роутинга на handler. Сам upsert делает
 * ClientBonusOperationHandler, потому что одна и та же сущность приезжает двумя
 * разными типами событий с разными наборами полей.
 *
 * customEventRequiredFields для 'rejected' обязателен: ValidatesEventData
 * считает стандартными только created/updated/deleted, для остальных типов
 * (в т.ч. 'rejected') по умолчанию требует event_id/id/source — а в payload
 * отказа поля 'id' нет вообще. Без явного переопределения каждое событие
 * bonus_operation.rejected проваливало бы validate() и тихо ACK'алось бы
 * консьюмером как невалидное, ни разу не долетев до handler'а.
 */
class ClientBonusOperationEventTransformer implements EventTransformer
{
  use ValidatesEventData;

  protected array $requiredFields = [
    'operation_id',
  ];

  protected array $customEventRequiredFields = [
    'rejected' => ['operation_id'],
  ];

  public function toEvent(Model $model, ?object $relatedModel = null, ?string $eventId = null): array
  {
    return [];
  }

  public function fromEvent(array $data): array
  {
    return [];
  }
}
