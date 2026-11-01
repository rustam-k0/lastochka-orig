<?php

namespace App\Transformers;

use App\Models\Brand;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BrandEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;

    protected array $requiredFields = [
        'event_id',
        'id',
        'source',
        'title',
    ];

    /**
     * Преобразовать модель Brand в событие для RabbitMQ
     *
     * @param Brand $brand
     */
    public function toEvent(Model $brand, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $brand->updated_at ?? now(),
            'id' => (string) $brand->id,
            'source' => config('rabbitmq-events.source'),
            'title' => $brand->title,
            'link' => $brand->link,
            'country_origin' => $brand->country_origin,
            'country_origin_code' => $brand->country_origin_code,
            'country_production' => $brand->country_production,
            'country_production_code' => $brand->country_production_code,
            'certificate_url' => $brand->certificate_path ? PathNormalizer::url($brand->certificate_path) : null,
            'certificate_name' => $brand->certificate_name,
            'position' => (int) $brand->position,
            'is_active' => (bool) $brand->is_active,
            'logo_image_url' => $this->getLogoImageUrl($brand),
            'custom_checkboxes' => json_decode($brand->custom_checkboxes ?: '{}', true) ?: [],
            'created_at' => $brand->created_at?->toIso8601String(),
            'updated_at' => $brand->updated_at?->toIso8601String(),
            'deleted_at' => $brand->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * Преобразовать событие в атрибуты модели (для входящих событий)
     * Не используется, так как мы только отправляем события
     */
    public function fromEvent(array $data): array
    {
        return [
            'title' => $data['title'] ?? 'Unknown',
            'position' => $data['position'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    /**
     * Получить URL логотипа бренда
     */
    protected function getLogoImageUrl(Brand $brand): ?string
    {
        if (!$brand->relationLoaded('logoImage')) {
            $brand->load('logoImage');
        }

        if ($brand->logoImage && $brand->logoImage->path) {
            return PathNormalizer::url($brand->logoImage->path);
        }

        return null;
    }
}
