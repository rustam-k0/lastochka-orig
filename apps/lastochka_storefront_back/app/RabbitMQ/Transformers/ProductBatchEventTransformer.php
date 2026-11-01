<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\RabbitMQ\Support\NetworkCatalog;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;

/**
 * Validate-only трансформер батч-событий товаров от 1С
 * (product.price_batch_updated / stock_batch_updated / batch_updated).
 *
 * Реальная обработка — App\RabbitMQ\Handlers\ProductBatchHandler:
 * EventConsumer::processEvent() роутит на handler ПОСЛЕ validate(),
 * стандартный metadata-пайплайн не задействован (у батч-конверта нет
 * верхнеуровневого id). toEvent()/fromEvent() не используются:
 * платформа батчи не публикует.
 */
final class ProductBatchEventTransformer implements EventTransformer
{
    use ValidatesEventData {
        getRequiredFieldsForEventType as baseRequiredFieldsForEventType;
    }

    /** @var array<int, string> */
    protected array $requiredFields = ['event_id', 'event_type', 'shop_code', 'source', 'items'];

    /**
     * Все три батч-типа обязаны быть здесь: для неизвестного кастомного
     * event_type трейт требует ['event_id', 'id', 'source'], а `id` в
     * батч-конверте отсутствует по контракту.
     *
     * @var array<string, array<int, string>>
     */
    protected array $customEventRequiredFields = [
        'price_batch_updated' => ['event_id', 'event_type', 'shop_code', 'source', 'items'],
        'stock_batch_updated' => ['event_id', 'event_type', 'shop_code', 'source', 'items'],
        'batch_updated' => ['event_id', 'event_type', 'shop_code', 'source', 'items'],
    ];

    /**
     * Сетевой каталог (контракт docs/specs/2026-08-09-1c-network-catalog-contract.md,
     * раздел 3): конверт `batch_updated` приходит без shop_code — карточка
     * глобальна и магазину не принадлежит. Для price/stock-батчей shop_code
     * обязателен всегда (разделы 4-5). Флаг выключен → валидация байт-в-байт
     * легаси.
     *
     * @return array<int, string>
     */
    protected function getRequiredFieldsForEventType(string $eventType): array
    {
        $fields = $this->baseRequiredFieldsForEventType($eventType);

        if ($eventType === 'batch_updated' && NetworkCatalog::enabled()) {
            $fields = array_values(array_diff($fields, ['shop_code']));
        }

        return $fields;
    }

    public function toEvent(Model $model, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [];
    }

    public function fromEvent(array $eventData): array
    {
        return [];
    }
}
