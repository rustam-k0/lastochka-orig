<?php

namespace App\Transformers;

use App\Models\Category;
use App\Models\MerchantBanner;
use App\Models\Product;
use App\Models\Shop;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MerchantBannerEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use ResolvesExternalReferences;

    protected array $requiredFields = [
        'event_id',
        'id',
        'source',
        'title',
    ];

    /**
     * Внешние ссылки для разрешения (при входящих событиях)
     */
    protected array $externalReferences = [
        'shop_id' => [
            'type' => Shop::class,
            'source_field' => 'shop_source',
            'id_field' => 'shop_id',
            'nullable' => false,
        ],
        'product_id' => [
            'type' => Product::class,
            'source_field' => 'product_source',
            'id_field' => 'product_id',
            'nullable' => true,
        ],
        'category_id' => [
            'type' => Category::class,
            'source_field' => 'category_source',
            'id_field' => 'category_id',
            'nullable' => true,
        ],
    ];

    /**
     * Преобразовать модель MerchantBanner в событие для RabbitMQ
     *
     * @param MerchantBanner $banner
     */
    public function toEvent(Model $banner, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $banner->updated_at ?? now(),
            'id' => (string) $banner->id,
            'source' => config('rabbitmq-events.source'),
            'title' => $banner->title,
            'link_type' => $banner->link_type,
            'is_active' => (bool) $banner->is_active,
            'position' => (int) $banner->position,
            'image_url' => $this->getImageUrl($banner),
            'custom_checkboxes' => json_decode($banner->custom_checkboxes ?: '{}', true) ?: [],
            'created_at' => $banner->created_at?->toIso8601String(),
            'updated_at' => $banner->updated_at?->toIso8601String(),
            'deleted_at' => $banner->deleted_at?->toIso8601String(),
        ];

        // Добавить shop_id + shop_source через external reference
        $this->addShopReference($eventData, $banner);

        // Добавить product_id + product_source если link_type = product
        $this->addProductReference($eventData, $banner);

        // Добавить category_id + category_source если link_type = category
        $this->addCategoryReference($eventData, $banner);

        // Добавить display_category_id + display_category_source
        // (категория, на странице которой показывается баннер; null = главная)
        $this->addDisplayCategoryReference($eventData, $banner);

        return $eventData;
    }

    /**
     * Преобразовать событие в атрибуты модели (для входящих событий)
     * Не используется, так как мы только отправляем события
     */
    public function fromEvent(array $data): array
    {
        return [
            'title' => $data['title'] ?? 'Unknown',
            'link_type' => $data['link_type'] ?? 'none',
            'is_active' => $data['is_active'] ?? true,
            'position' => $data['position'] ?? 0,
        ];
    }

    /**
     * Получить URL изображения баннера
     */
    protected function getImageUrl(MerchantBanner $banner): ?string
    {
        if (!$banner->relationLoaded('image')) {
            $banner->load('image');
        }

        if ($banner->image && $banner->image->path) {
            return PathNormalizer::url($banner->image->path);
        }

        return null;
    }

    /**
     * Добавить external reference для магазина
     */
    protected function addShopReference(array &$eventData, MerchantBanner $banner): void
    {
        if ($banner->shop_id) {
            $shopMeta = EventSyncMetadata::where([
                'entity_type' => 'shops',
                'entity_id' => $banner->shop_id,
            ])->first();

            if ($shopMeta) {
                $eventData['shop_id'] = $shopMeta->external_id;
                $eventData['shop_source'] = $shopMeta->source;
            } else {
                $shop = $banner->shop;
                $eventData['shop_id'] = $shop?->code ?? (string) $banner->shop_id;
                $eventData['shop_source'] = config('rabbitmq-events.source');
            }
        }
    }

    /**
     * Добавить external reference для товара
     */
    protected function addProductReference(array &$eventData, MerchantBanner $banner): void
    {
        if ($banner->link_type === MerchantBanner::LINK_TYPE_PRODUCT && $banner->product_id) {
            $productMeta = EventSyncMetadata::where([
                'entity_type' => 'products',
                'entity_id' => $banner->product_id,
            ])->first();

            if ($productMeta) {
                $eventData['product_id'] = $productMeta->external_id;
                $eventData['product_source'] = $productMeta->source;
            } else {
                $eventData['product_id'] = (string) $banner->product_id;
                $eventData['product_source'] = config('rabbitmq-events.source');
            }
        } else {
            $eventData['product_id'] = null;
            $eventData['product_source'] = null;
        }
    }

    /**
     * Добавить external reference для категории размещения баннера
     */
    protected function addDisplayCategoryReference(array &$eventData, MerchantBanner $banner): void
    {
        if ($banner->display_category_id) {
            $categoryMeta = EventSyncMetadata::where([
                'entity_type' => 'categories',
                'entity_id' => $banner->display_category_id,
            ])->first();

            if ($categoryMeta) {
                $eventData['display_category_id'] = $categoryMeta->external_id;
                $eventData['display_category_source'] = $categoryMeta->source;
            } else {
                $eventData['display_category_id'] = (string) $banner->display_category_id;
                $eventData['display_category_source'] = config('rabbitmq-events.source');
            }
        } else {
            $eventData['display_category_id'] = null;
            $eventData['display_category_source'] = null;
        }
    }

    /**
     * Добавить external reference для категории
     */
    protected function addCategoryReference(array &$eventData, MerchantBanner $banner): void
    {
        if ($banner->link_type === MerchantBanner::LINK_TYPE_CATEGORY && $banner->category_id) {
            $categoryMeta = EventSyncMetadata::where([
                'entity_type' => 'categories',
                'entity_id' => $banner->category_id,
            ])->first();

            if ($categoryMeta) {
                $eventData['category_id'] = $categoryMeta->external_id;
                $eventData['category_source'] = $categoryMeta->source;
            } else {
                $eventData['category_id'] = (string) $banner->category_id;
                $eventData['category_source'] = config('rabbitmq-events.source');
            }
        } else {
            $eventData['category_id'] = null;
            $eventData['category_source'] = null;
        }
    }
}
