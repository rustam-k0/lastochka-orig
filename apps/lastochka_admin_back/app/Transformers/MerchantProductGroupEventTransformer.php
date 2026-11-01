<?php

namespace App\Transformers;

use App\Models\MerchantProductGroup;
use App\Models\Shop;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MerchantProductGroupEventTransformer implements EventTransformer
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
    ];

    /**
     * Преобразовать модель MerchantProductGroup в событие для RabbitMQ
     *
     * @param MerchantProductGroup $group
     */
    public function toEvent(Model $group, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $group->updated_at ?? now(),
            'id' => (string) $group->id,
            'source' => config('rabbitmq-events.source'),
            'title' => $group->title,
            // link генерируется автоматически из title в
            // MerchantProductGroup::boot() (creating/updating) — публикуем
            // его потребителям как slug, по аналогии с категориями.
            'slug' => $group->link,
            'description' => $group->description,
            'meta' => $group->meta,
            'show_on_main' => (bool) $group->show_on_main,
            'show_on_merchant_page' => (bool) $group->show_on_merchant_page,
            'show_in_merchant_catalog' => (bool) $group->show_in_merchant_catalog,
            'show_on_search' => (bool) $group->show_on_search,
            'sort_order' => (int) $group->sort_order,
            'is_active' => (bool) $group->is_active,
            'is_recommended' => (bool) $group->is_recommended,
            'is_cart_page' => (bool) $group->is_cart_page,
            'is_complex_solution' => (bool) $group->is_complex_solution,
            // Способ наполнения (App\Enums\ProductGroupFillType): у автоматической
            // группы products пуст, товары собирает витрина по критерию.
            'fill_type' => $group->fillType()->value,
            'image_url' => $this->getImageUrl($group),
            'products' => $this->getProductIds($group),
            'filter_values' => $group->filterValues->map(fn ($v) => [
                'value' => $v->value,
                'filter_name' => $v->filter->name,
            ])->toArray(),
            'custom_checkboxes' => json_decode($group->custom_checkboxes ?: '{}', true) ?: [],
            'created_at' => $group->created_at?->toIso8601String(),
            'updated_at' => $group->updated_at?->toIso8601String(),
            'deleted_at' => $group->deleted_at?->toIso8601String(),
        ];

        // Добавить shop_id + shop_source через external reference
        if ($group->shop_id) {
            $shopMeta = EventSyncMetadata::where([
                'entity_type' => 'shops',
                'entity_id' => $group->shop_id,
            ])->first();

            if ($shopMeta) {
                $eventData['shop_id'] = $shopMeta->external_id;
                $eventData['shop_source'] = $shopMeta->source;
            } else {
                $shop = $group->shop;
                $eventData['shop_id'] = $shop?->code ?? (string) $group->shop_id;
                $eventData['shop_source'] = config('rabbitmq-events.source');
            }
        }

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
            'meta' => $data['meta'] ?? null,
            'show_on_main' => $data['show_on_main'] ?? false,
            'show_on_merchant_page' => $data['show_on_merchant_page'] ?? true,
            'show_in_merchant_catalog' => $data['show_in_merchant_catalog'] ?? false,
            'show_on_search' => $data['show_on_search'] ?? false,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'is_recommended' => $data['is_recommended'] ?? false,
            'is_cart_page' => $data['is_cart_page'] ?? false,
            'is_complex_solution' => $data['is_complex_solution'] ?? false,
        ];
    }

    /**
     * Получить URL изображения группы
     */
    protected function getImageUrl(MerchantProductGroup $group): ?string
    {
        if (!$group->relationLoaded('image')) {
            $group->load('image');
        }

        if ($group->image && $group->image->path) {
            return PathNormalizer::url($group->image->path);
        }

        return null;
    }

    /**
     * Получить массив товаров в группе с external references
     */
    protected function getProductIds(MerchantProductGroup $group): array
    {
        if (!$group->relationLoaded('products')) {
            $group->load('products');
        }

        $products = [];

        foreach ($group->products as $product) {
            $productMeta = EventSyncMetadata::where([
                'entity_type' => 'products',
                'entity_id' => $product->id,
            ])->first();

            $quantity = $product->pivot->quantity ?? 1;

            if ($productMeta) {
                $products[] = [
                    'product_id' => $productMeta->external_id,
                    'product_source' => $productMeta->source,
                    'quantity' => $quantity,
                ];
            } else {
                $products[] = [
                    'product_id' => (string) $product->id,
                    'product_source' => config('rabbitmq-events.source'),
                    'quantity' => $quantity,
                ];
            }
        }

        return $products;
    }
}
