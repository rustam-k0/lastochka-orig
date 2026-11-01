<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ProductIssueReportEventTransformer extends BaseEventTransformer
{
    protected function requiredFields(): array
    {
        return ['event_id', 'id'];
    }

    public function toEvent(Model $report, ?object $shop = null, ?string $eventId = null): array
    {
        $report->loadMissing(['user', 'product', 'store']);

        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'id' => $report->id,
            'user_id' => $report->user_id,
            'user_phone' => $report->user?->phone,
            'user_email' => $report->email,
            'product_id' => $report->product_id,
            'product_title' => $report->product?->title,
            'product_sku' => $report->product?->sku,
            'shop_code' => $report->store?->code,
            'type' => $report->type,
            'message' => $report->message,
        ];
    }

    public function fromEvent(array $data): array
    {
        return [
            'email' => $data['user_email'] ?? $data['email'] ?? '',
            'type' => $data['type'] ?? 'description_error',
            'message' => $data['message'] ?? '',
        ];
    }

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        if (! isset($eventData['id'])) {
            return null;
        }

        return \App\Models\ProductIssueReport::where('id', $eventData['id'])->first();
    }
}
