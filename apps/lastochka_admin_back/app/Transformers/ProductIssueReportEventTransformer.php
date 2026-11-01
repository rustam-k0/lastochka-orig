<?php

namespace App\Transformers;

use App\Models\Client;
use App\Models\ProductIssueReport;
use App\Models\Shop;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductIssueReportEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;

    protected array $requiredFields = [
        'event_id',
        'id',
    ];

    public function toEvent(Model $report, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $metadata = EventSyncMetadata::where([
            'entity_type' => 'product_issue_reports',
            'entity_id' => $report->id,
        ])->first();

        $reportId = $metadata ? $metadata->external_id : (string) $report->id;
        $reportSource = $metadata ? $metadata->source : config('rabbitmq-events.source');

        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $report->updated_at ?? now(),
            'id' => $reportId,
            'source' => $reportSource,
            'client_id' => $report->client_id,
            'product_id' => $report->product_id,
            'shop_code' => $report->shop?->code,
            'user_email' => $report->email,
            'type' => $report->type,
            'message' => $report->message,
            'created_at' => $report->created_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
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

    public function afterCreate(Model $report, array $eventData): void
    {
        $this->resolveRelations($report, $eventData);
    }

    public function afterUpdate(Model $report, array $eventData): void
    {
        $this->resolveRelations($report, $eventData);
    }

    private function resolveRelations(Model $report, array $eventData): void
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

        if (isset($eventData['product_id'])) {
            $metadata = EventSyncMetadata::where([
                'entity_type' => 'products',
                'external_id' => $eventData['product_id'],
                'source' => $eventData['source'] ?? null,
            ])->first();

            if ($metadata) {
                $updates['product_id'] = $metadata->entity_id;
            }
        }

        if (!empty($updates)) {
            $report->updateQuietly($updates);
        }
    }

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        if (!isset($eventData['id'])) {
            return null;
        }

        return ProductIssueReport::where('id', $eventData['id'])->first();
    }
}
