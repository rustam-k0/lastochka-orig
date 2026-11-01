<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\BonusTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class BonusOperationEventTransformer extends BaseEventTransformer
{
    /**
     * loyalty_id намеренно не в списке: очередь storefront подписана на
     * bonus_operation.created, то есть получает и собственные факты, а
     * событие штатно публикуется и для клиента без карты лояльности
     * (см. toEvent). Валидация выполняется ДО отсечения по event_source,
     * поэтому обязательный loyalty_id валил бы каждое такое собственное
     * эхо в ERROR-лог ещё до идемпотентной проверки по operation_id.
     *
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'operation_id', 'type', 'amount'];
    }

    /**
     * Преобразовать модель BonusTransaction в событие для RabbitMQ.
     *
     * @param  BonusTransaction  $model
     * @return array<string, mixed>
     */
    public function toEvent(Model $model, ?object $shop = null, ?string $eventId = null): array
    {
        $user = $model->user;
        $order = $model->order;

        return [
            'event_id'          => $eventId ?? Str::uuid()->toString(),
            'event_timestamp'   => optional($model->created_at)->toIso8601String() ?? now()->toIso8601String(),
            'event_source'      => config('rabbitmq-events.source'),
            'shop_code'         => config('rabbitmq-events.shop_code', 'STOREFRONT'),
            'source'            => config('rabbitmq-events.source'),
            'operation_id'      => $model->operation_id,
            'loyalty_id'        => $user?->loyalty_id,
            'client_phone'      => $user?->phone,
            'balance_after'     => (int) $model->balance_after,
            'description'       => $model->description,
            'type'              => $model->type,
            'amount'            => (int) $model->amount,
            'operation_source'  => $model->source,           // online|manual|initial
            'order_external_id' => $order?->external_id,
            'occurred_at'       => optional($model->created_at)->toIso8601String(),
        ];
    }

    /**
     * Входящие события не обрабатываются (bonus_operations — только outbound).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        return [];
    }
}
