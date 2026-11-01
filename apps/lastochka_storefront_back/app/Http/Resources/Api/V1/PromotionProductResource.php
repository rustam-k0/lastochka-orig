<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Promotion;
use App\Models\User;
use App\Services\Pricing\ProductPriceResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Product
 */
final class PromotionProductResource extends JsonResource
{
    private ?Promotion $promotion = null;

    private ?User $user = null;

    /**
     * Установить акцию для расчёта скидки.
     */
    public function setPromotion(?Promotion $promotion): self
    {
        $this->promotion = $promotion;

        return $this;
    }

    /**
     * Установить пользователя для проверки избранного.
     */
    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $promotion = $this->promotion;
        $user = $this->user ?? $request->user();

        // Сетевой каталог: цена и старая цена — из оффера магазина акции
        // (constrained eager load storeProducts). Связь не загружена
        // (легаси-режим) — прежний фолбэк на колонки карточки.
        $storeContext = $this->resource->storeContext();

        $price = (float) ($storeContext !== null ? $storeContext->price : $this->price);
        $priceOldRaw = $storeContext !== null ? $storeContext->price_old : $this->price_old;
        $priceOld = $priceOldRaw !== null ? (float) $priceOldRaw : null;

        // Базовая цена (без скидок)
        $basePrice = $priceOld ?? $price;

        // Цена со скидкой акции (с сохранением копеек, как в карточке товара)
        $discountedPrice = $promotion
            ? round($basePrice * (1 - $promotion->discount_percent / 100), 2)
            : $price;

        // Процент скидки
        $discountPercent = $promotion
            ? $promotion->discount_percent
            : ($priceOld ? (int) round(100 - ($price / $priceOld * 100)) : null);

        // Цены от количества (MID-308): ступень для одной порции приоритетнее акции.
        // priceOld этого ресурса не nullable: без зачёркнутой цены отдаём саму цену.
        $tierResolver = app(ProductPriceResolver::class);
        $tierQuote = $tierResolver->portionLadder($this->resource) !== []
            ? $tierResolver->quote($this->resource, 1.0)
            : null;

        if ($tierQuote !== null && $tierQuote->tierApplied) {
            $discountedPrice = $tierQuote->price;
            $basePrice = $tierQuote->priceOld ?? $tierQuote->price;
            $discountPercent = $tierQuote->priceOld !== null && $tierQuote->priceOld > 0
                ? (int) round(100 - ($tierQuote->price / $tierQuote->priceOld * 100))
                : null;
        }

        return [
            'id' => (int) $this->id,
            'sku' => (string) $this->sku,
            'title' => (string) $this->title,
            'slug' => $this->slug,
            'price' => round((float) $discountedPrice, 2),
            'priceOld' => round((float) $basePrice, 2),
            'discountPercent' => $discountPercent ? (int) $discountPercent : null,
            /** @var list<array{minQuantity: float, price: float}> Цены от количества; [] если лесенки нет */
            'priceTiers' => $tierResolver->tiersForApi($this->resource),
            'currency' => (string) $this->currency,
            /** @var int|null Вес/объём единицы товара в граммах (для жидкостей: мл) */
            'weight' => $this->weight !== null ? (int) $this->weight : null,
            'measurementUnit' => $this->measurement_unit?->value ?? 'piece',
            /** @var string Русский лейбл единицы: шт/гр/кг/л/мл */
            'measurementUnitLabel' => $this->measurement_unit?->label() ?? 'шт',
            'images' => ImageResource::collection($this->whenLoaded('images')),
            'quantityStep' => (int) ($this->quantity_step ?? 1),
            'stockQuantity' => (int) $this->quantity,
            'isFavorite' => $user ? (bool) $this->resource->isFavorite($user) : false,
            'adultOnly' => (bool) $this->adult_only,
            'pickupOnly' => (bool) $this->pickup_only,
            'hasSupplements' => $this->when(
                $this->resource->getAttribute('supplements_count') !== null,
                fn () => $this->supplements_count > 0,
            ),
            'hasRequiredSupplements' => $this->when(
                $this->resource->getAttribute('supplements_required_count') !== null,
                fn () => $this->supplements_required_count > 0,
            ),
        ];
    }

    /**
     * Создать коллекцию ресурсов с установленной акцией.
     */
    public static function collectionWithPromotion($resource, Promotion $promotion, ?User $user = null): array
    {
        app(ProductPriceResolver::class)->preloadProducts($resource);

        return $resource->map(function ($item) use ($promotion, $user) {
            return (new self($item))
                ->setPromotion($promotion)
                ->setUser($user)
                ->toArray(request());
        })->all();
    }
}
