<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PreorderEventTransformer extends BaseEventTransformer
{
    protected function requiredFields(): array
    {
        return ['event_id', 'id'];
    }

    public function toEvent(Model $preorder, ?object $shop = null, ?string $eventId = null): array
    {
        $preorder->loadMissing(['user', 'product', 'store']);

        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => ($preorder->updated_at ?? $preorder->created_at ?? now())->toIso8601String(),
            'id' => $preorder->id,
            'user_id' => $preorder->user_id,
            'user_phone' => $preorder->user?->phone,
            'product_id' => $preorder->product_id,
            'product_title' => $preorder->product?->title,
            'product_sku' => $preorder->product?->sku,
            'shop_code' => $preorder->store?->code,
            'full_name' => $preorder->full_name,
            'phone' => $preorder->phone,
            'email' => $preorder->email,
            'quantity' => $preorder->quantity,
            'comment' => $preorder->comment,
            'status' => $preorder->status->value ?? $preorder->status,
        ];
    }

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

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        if (! isset($eventData['id'])) {
            return null;
        }

        return \App\Models\Preorder::where('id', $eventData['id'])->first();
    }
}
