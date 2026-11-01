<?php

namespace App\Transformers;

use App\Models\Client;
use App\Models\Preorder;
use App\Models\Shop;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PreorderEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;

    protected array $requiredFields = [
        'event_id',
        'id',
    ];

    /**
     * Преобразовать модель Preorder в событие для RabbitMQ
     *
     * @param Preorder $preorder
     */
    public function toEvent(Model $preorder, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $metadata = EventSyncMetadata::where([
            'entity_type' => 'preorders',
            'entity_id' => $preorder->id,
        ])->first();

        $preorderId = $metadata ? $metadata->external_id : (string) $preorder->id;
        $preorderSource = $metadata ? $metadata->source : config('rabbitmq-events.source');

        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $preorder->updated_at ?? now(),
            'id' => $preorderId,
            'source' => $preorderSource,
            'client_id' => $preorder->client_id,
            'product_id' => $preorder->product_id,
            'shop_code' => $preorder->shop?->code,
            'full_name' => $preorder->full_name,
            'phone' => $preorder->phone,
            'email' => $preorder->email,
            'quantity' => $preorder->quantity,
            'comment' => $preorder->comment,
            'status' => $preorder->status,
            'created_at' => $preorder->created_at?->toIso8601String(),
            'updated_at' => $preorder->updated_at?->toIso8601String(),
        ];

        return $eventData;
    }

    /**
     * Преобразовать событие в атрибуты модели (для входящих событий)
     */
    public function fromEvent(array $data): array
    {
        return [
            'full_name' => $data['full_name'] ?? '',
            'phone' => $data['phone'] ?? '',
            'email' => $data['email'] ?? null,
            'quantity' => $data['quantity'] ?? 1,
            'comment' => $data['comment'] ?? null,
            'status' => $data['status'] ?? 'new',
        ];
    }

    /**
     * Хук после создания — разрешение связей
     */
    public function afterCreate(Model $preorder, array $eventData): void
    {
        $this->resolveRelations($preorder, $eventData);
    }

    /**
     * Хук после обновления — разрешение связей
     */
    public function afterUpdate(Model $preorder, array $eventData): void
    {
        $this->resolveRelations($preorder, $eventData);
    }

    /**
     * Разрешение связей client и shop по данным из события
     */
    private function resolveRelations(Model $preorder, array $eventData): void
    {
        $updates = [];

        if (isset($eventData['user_phone'])) {
            $client = Client::where('phone', $eventData['user_phone'])->first();
            if ($client) {
                $updates['client_id'] = $client->id;
            }
        }

        if (isset($eventData['shop_code'])) {
            $shop = Shop::where('code', $eventData['shop_code'])->first();
            if ($shop) {
                $updates['shop_id'] = $shop->id;
            }
        }

        if (!empty($updates)) {
            $preorder->updateQuietly($updates);
        }
    }

    /**
     * Поиск существующей сущности для дедупликации
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        if (!isset($eventData['id'])) {
            return null;
        }

        return Preorder::where('id', $eventData['id'])->first();
    }
}
