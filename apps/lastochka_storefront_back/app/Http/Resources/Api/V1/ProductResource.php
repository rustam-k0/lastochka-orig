<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Bonus\StoreBonusEarnResolver;
use App\Services\Comparison\UserComparisonResolver;
use App\Services\Pricing\ProductPriceResolver;
use App\Services\Images\ImageThumbnails;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Product
 */
final class ProductResource extends JsonResource
{
    private ?int $selectedVariantId = null;

    /** Кеш {@see self::familyStockIgnored()} — метод зовётся на каждую вариацию. */
    private ?bool $familyStockIgnored = null;

    public function withSelectedVariant(?int $variantId): self
    {
        $this->selectedVariantId = $variantId;

        return $this;
    }

    /**
     * Лесенки «цены от количества» (MID-308) всей выдачи грузятся одним
     * запросом. При выключенном модуле preloadProducts() ничего не делает.
     *
     * Это единственная точка пачечной предзагрузки лесенок для выдачи
     * товаров: собирать выдачу в обход неё (например, `$products->map(fn
     * ($p) => new ProductResource($p))`) НЕЛЬЗЯ — при включённом модуле
     * `toArray()` каждого товара дёрнет `tiersForApi()` отдельно, и на
     * листинге это запрос к product_price_tiers на каждый товар (N+1).
     *
     * @param  mixed  $resource
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        app(ProductPriceResolver::class)->preloadProducts($resource);

        return parent::collection($resource);
    }

    public function toArray(Request $request): array
    {
        // (float)/(int)-касты ценовых полей ниже обязательны: Scramble теряет
        // array-shape calculatePriceData() и без них документирует цены как string.
        $priceData = $this->calculatePriceData();

        return [
            'id' => (int) $this->id,
            'storeId' => $this->store_id === null ? null : (int) $this->store_id,
            'sku' => (string) $this->sku,
            'title' => (string) $this->title,
            /** @var string|null ЧПУ-слаг для ссылки на карточку; null у товаров без названия */
            'slug' => $this->slug,
            'description' => $this->description,
            /** @var float Актуальная цена с учётом скидки (ручной или акционной) */
            'price' => (float) $priceData['price'],
            /** @var float|null Цена до скидки; null если скидки нет */
            'priceOld' => $priceData['priceOld'] !== null ? (float) $priceData['priceOld'] : null,
            /** @var int|null Процент скидки; null если скидки нет */
            'discountPercent' => $priceData['discountPercent'] !== null ? (int) $priceData['discountPercent'] : null,
            'bonusEarn' => $this->calculateBonusEarn($priceData),
            /** @var list<array{minQuantity: float, price: float}> Цены от количества: minQuantity в единицах quantity корзины, price за порцию (как поле price); [] если лесенки нет */
            'priceTiers' => app(ProductPriceResolver::class)->tiersForApi($this->resource),
            'currency' => (string) $this->currency,
            'images' => $this->when(
                $this->resource->relationLoaded('images'),
                function () {
                    $images = $this->images;
                    if (! app(ModuleConfigService::class)->isVideoEnabled()) {
                        $images = $images->where('type', '!=', 'video');
                    }

                    return ImageResource::collection($images);
                },
                []
            ),
            'isFavorite' => $this->getIsFavorite($request),
            'isInComparison' => app(UserComparisonResolver::class)
                ->contains($request->user(), (int) $this->id),
            'servedWarm' => (bool) $this->served_warm,
            'adultOnly' => (bool) $this->adult_only,
            'isMarked' => (bool) $this->is_marked,
            'pickupOnly' => (bool) $this->pickup_only,
            'composition' => $this->composition,
            'additionalInfo' => $this->additional_info,
            'proteins' => $this->proteins ? (float) $this->proteins : null,
            'fats' => $this->fats ? (float) $this->fats : null,
            'carbohydrates' => $this->carbohydrates ? (float) $this->carbohydrates : null,
            'calories' => $this->calories ? (float) $this->calories : null,
            // Вес единицы товара в граммах — подпись «230 г» у штучных.
            'weight' => $this->weight !== null ? (int) $this->weight : null,
            'attributes' => $this->when(
                $this->resource->relationLoaded('attributes'),
                fn () => $this->buildAttributes(),
                []
            ),
            'rating' => $this->rating ? (float) $this->rating : null,
            'reviewsCount' => (int) $this->reviews_count,
            'supplements' => $this->when(
                $this->resource->relationLoaded('supplements'),
                fn () => SupplementResource::collection($this->supplements),
                []
            ),
            'hasSupplements' => $this->when(
                $this->resource->getAttribute('supplements_count') !== null,
                fn () => $this->supplements_count > 0,
            ),
            'hasRequiredSupplements' => $this->when(
                $this->resource->getAttribute('supplements_required_count') !== null,
                fn () => $this->supplements_required_count > 0,
            ),
            'quantityStep' => (int) ($this->quantity_step ?? 1),
            'measurementUnit' => $this->measurement_unit?->value ?? 'piece',
            'measurementUnitLabel' => $this->measurement_unit?->label() ?? 'шт',
            'stockQuantity' => $this->stockQuantity(),
            'videoUrl' => $this->when(
                app(ModuleConfigService::class)->isVideoEnabled() && $this->video_path,
                fn () => ImageResource::toFullUrl($this->video_path),
            ),
            'brand' => $this->when(
                app(ModuleConfigService::class)->isBrandsEnabled() && $this->resource->relationLoaded('brand'),
                fn () => $this->brand ? new BrandResource($this->brand) : null,
            ),
            'groupQuantity' => $this->when(
                $this->pivot && $this->pivot->quantity !== null,
                fn () => (int) $this->pivot->quantity,
            ),
            'isPreorder' => $this->when(
                app(ModuleConfigService::class)->isPreordersEnabled(),
                fn () => ! $this->isStockIgnored() && $this->stockQuantity() < $this->resource->minSaleStock(),
            ),
            'availableFrom' => $this->when(
                app(ModuleConfigService::class)->isProductReadyTimeEnabled(),
                fn () => $this->readyAt()?->toIso8601String(),
            ),
            /** @var string|null Дата доставки (Y-m-d); присутствует только в выдачах, где резолвер её посчитал */
            'deliveryDate' => $this->when(
                array_key_exists('delivery_date', $this->resource->getAttributes()),
                fn (): ?string => $this->resource->getAttribute('delivery_date'),
            ),
            /** @var string|null Дата самовывоза (Y-m-d); присутствует только в выдачах, где резолвер её посчитал */
            'pickupDate' => $this->when(
                array_key_exists('pickup_date', $this->resource->getAttributes()),
                fn (): ?string => $this->resource->getAttribute('pickup_date'),
            ),
            'isConfigurable' => $this->is_configurable,
            'customCheckboxes' => (object) ($this->custom_checkboxes ?? []),
            'priceRange' => $this->when(
                $this->is_configurable && $this->resource->relationLoaded('children'),
                fn () => $this->buildPriceRange(),
            ),
            'configurableAttributes' => $this->when(
                $this->is_configurable && $this->resource->relationLoaded('configurableAttributes'),
                fn () => $this->buildConfigurableAttributes(),
            ),
            'variants' => $this->when(
                $this->is_configurable && $this->resource->relationLoaded('children'),
                fn () => $this->buildVariants(),
            ),
            'defaultVariantId' => $this->when(
                $this->is_configurable,
                fn () => $this->resource->getDefaultVariant()?->id,
            ),
            'selectedVariantId' => $this->when(
                $this->is_configurable,
                fn () => $this->selectedVariantId,
            ),
            'category' => $this->whenLoaded('categories', function () {
                $category = $this->categories->first();

                return $category === null ? null : [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                    'slug' => (string) $category->slug,
                ];
            }),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
            ])->all()),
            'primary_category' => $this->whenLoaded('categories', fn () => $this->categories->first()?->only(['id', 'title'])),
        ];
    }

    /**
     * Бонусы, которые начислятся за единицу товара — бейдж «+N бонусов»
     * на карточке и в плитке каталога.
     *
     * Зеркалит правило начисления из CartResource и
     * CreateOrderAction::previewBonus: eligible-сумма считается только по
     * позициям без скидки ({@see \App\Services\Discount\ItemDiscountResolver}),
     * поэтому у товара со скидкой бейджа нет. Наличие скидки берём из уже
     * посчитанного priceData: `priceOld !== null` выставляется и ручной
     * скидкой (в сетевом каталоге — скидкой оффера магазина), и активной
     * акцией — то есть ровно теми же двумя условиями, но без лишнего
     * запроса к promotions.
     *
     * @param  array{price: float|int, priceOld: float|int|null, discountPercent: int|null}  $priceData
     */
    private function calculateBonusEarn(array $priceData): int
    {
        if ($priceData['priceOld'] !== null) {
            return 0;
        }

        $percent = app(StoreBonusEarnResolver::class)->earnPercentForProduct($this->resource);

        if ($percent === 0) {
            return 0;
        }

        return (int) floor((float) $priceData['price'] * $percent / 100);
    }

    /**
     * Массив характеристик для блока «Характеристики» мобилки.
     * Бренд — отдельная сущность (Brand), но мобилка рисует блок из attributes,
     * поэтому при включённом модуле брендов дописываем его виртуальным
     * атрибутом в конец списка.
     *
     * @return list<array{id: int, key: string, value: string, sortOrder: int}>
     */
    private function buildAttributes(): array
    {
        $attributes = ProductAttributeResource::collection($this->attributes)->resolve();

        $brandTitle = $this->brandAttributeTitle();
        if ($brandTitle !== null) {
            $attributes[] = [
                'id' => 0,
                'key' => 'Бренд',
                'value' => $brandTitle,
                'sortOrder' => $attributes === []
                    ? 0
                    : max(array_column($attributes, 'sortOrder')) + 1,
            ];
        }

        return $attributes;
    }

    private function brandAttributeTitle(): ?string
    {
        if (! app(ModuleConfigService::class)->isBrandsEnabled()) {
            return null;
        }

        if (! $this->resource->relationLoaded('brand') || ! $this->brand) {
            return null;
        }

        return (string) $this->brand->title;
    }

    /**
     * @return array{min: int, max: int}|null
     */
    private function buildPriceRange(): ?array
    {
        $ignoreStock = $this->familyStockIgnored();
        app(ProductPriceResolver::class)->preloadProducts($this->children);
        $childPrices = $this->children
            ->filter(fn ($c) => $c->is_active && ($ignoreStock || self::stockOf($c) >= $c->minSaleStock()))
            ->map(fn ($c) => (new self($c))->calculatePriceData()['price'])
            ->filter();

        if ($childPrices->isEmpty()) {
            return null;
        }

        return [
            'min' => round((float) $childPrices->min(), 2),
            'max' => round((float) $childPrices->max(), 2),
        ];
    }

    /**
     * @return list<array{code: string, name: string, values: list<array{id: int, label: string, available: bool}>}>
     */
    private function buildConfigurableAttributes(): array
    {
        $children = ($this->resource->relationLoaded('children') ? $this->children : collect())
            ->filter(fn ($c) => $c->is_active && $c->deleted_at === null);

        // Магазин в режиме ignore_stock — варианты доступны независимо от остатка.
        $ignoreStock = $this->familyStockIgnored();

        return $this->configurableAttributes->map(function ($cfgAttr) use ($children, $ignoreStock) {
            $valuesMap = [];

            foreach ($children as $child) {
                $attr = null;
                if ($child->relationLoaded('attributes')) {
                    $attr = $child->attributes->first(
                        fn ($a) => (string) $a->code === (string) $cfgAttr->attribute_code
                    );
                }
                if (!$attr) {
                    continue;
                }

                $valueId = (int) $attr->attribute_value_id;
                $label = (string) $attr->value;
                $code = (string) ($attr->attribute_value_code ?? $valueId ?: $label);

                if (!isset($valuesMap[$code])) {
                    $valuesMap[$code] = [
                        'id' => $valueId,
                        'code' => $code,
                        'label' => $label,
                        'variant_ids' => [],
                        'available' => false,
                    ];
                }

                $valuesMap[$code]['variant_ids'][] = (int) $child->id;
                $inStock = $ignoreStock || self::stockOf($child) >= $child->minSaleStock();
                if ($inStock) {
                    $valuesMap[$code]['available'] = true;
                }
            }

            $values = array_values($valuesMap);

            return [
                'code' => (string) $cfgAttr->attribute_code,
                'name' => (string) $cfgAttr->attribute_name,
                'position' => (int) $cfgAttr->position,
                'values' => $values,
            ];
        })->values()->all();
    }

    /**
     * @return list<array{id: int, sku: string, title: string, slug: string|null, price: float, priceOld: float|null, bonusEarn: int, stockQuantity: int, weight: int|null, measurementUnit: string, measurementUnitLabel: string, availability: string, images: list<array{id: int, path: string}>, attributes: array<string, int>, isDefault: bool}>
     */
    private function buildVariants(): array
    {
        $parent = $this->resource;
        app(ProductPriceResolver::class)->preloadProducts($this->children);

        return $this->children
            ->filter(fn ($c) => $c->is_active && $c->deleted_at === null)
            ->map(function ($child) use ($parent) {
                $child->setRelation('parent', $parent);
                $childPriceData = (new self($child))->calculatePriceData();

                $attrs = [];
                if ($child->relationLoaded('attributes')) {
                    foreach ($child->attributes as $attr) {
                        if ($attr->attribute_value_id) {
                            $attrs[(string) $attr->code] = (int) $attr->attribute_value_id;
                        }
                    }
                }

                $ownImages = $child->relationLoaded('images') ? $child->images : collect();
                $imageSource = $ownImages->isNotEmpty()
                    ? $ownImages
                    : ($parent->relationLoaded('images') ? $parent->images : collect());

                $images = [];
                foreach ($imageSource as $image) {
                    $images[] = [
                        'id' => (int) $image->id,
                        'path' => (string) ImageResource::toFullUrl($image->path),
                        'thumbPath' => ImageThumbnails::urlFor((string) $image->path),
                    ];
                }

                return [
                    'id' => (int) $child->id,
                    'sku' => (string) $child->sku,
                    'title' => (string) $child->title,
                    'slug' => $child->slug,
                    'price' => round((float) $childPriceData['price'], 2),
                    'priceOld' => $childPriceData['priceOld'] === null ? null : round((float) $childPriceData['priceOld'], 2),
                    'bonusEarn' => (new self($child))->calculateBonusEarn($childPriceData),
                    'priceTiers' => app(ProductPriceResolver::class)->tiersForApi($child),
                    'stockQuantity' => self::stockOf($child),
                    'weight' => $child->weight !== null ? (int) $child->weight : null,
                    'measurementUnit' => $child->measurement_unit?->value ?? 'piece',
                    'measurementUnitLabel' => $child->measurement_unit?->label() ?? 'шт',
                    'availability' => $this->buildAvailability($child),
                    'images' => $images,
                    'attributes' => $attrs,
                    'isDefault' => (bool) $child->is_default_variant,
                ];
            })
            ->values()
            ->all();
    }

    private function buildAvailability(\App\Models\Product $variant): string
    {
        if ($this->familyStockIgnored() || self::stockOf($variant) >= $variant->minSaleStock()) {
            return 'available';
        }

        if (app(ModuleConfigService::class)->isPreordersEnabled()) {
            return 'preorder';
        }

        return 'out_of_stock';
    }

    /**
     * Режим «не учитывать остатки» для вариативной семьи.
     *
     * В сетевом каталоге режим берётся у магазина оффера, а у родителя
     * собственного оффера может не быть (он есть только у вариаций) — тогда
     * смотрим на магазины офферов вариаций. Вне сетевого режима у вариаций
     * контекста нет и проверка сводится к прежней — по родителю.
     */
    private function familyStockIgnored(): bool
    {
        // Мемоизация: метод зовётся на каждую вариацию (buildAvailability).
        if ($this->familyStockIgnored !== null) {
            return $this->familyStockIgnored;
        }

        if ($this->resource->isStockIgnored()) {
            return $this->familyStockIgnored = true;
        }

        if (! $this->resource->relationLoaded('children')) {
            return $this->familyStockIgnored = false;
        }

        return $this->familyStockIgnored = $this->children->contains(
            fn ($child) => (bool) ($child->storeContext()?->store?->ignore_stock ?? false)
        );
    }

    /**
     * Остаток товара: в сетевом каталоге — остаток оффера загруженного
     * магазина, иначе колонка products.quantity.
     */
    private static function stockOf(\App\Models\Product $product): int
    {
        return $product->stockQuantity();
    }

    /**
     * Рассчитать цену с учётом ручной скидки и активной акции.
     * Применяется большая скидка из двух.
     *
     * @return array{price: float, priceOld: float|null, discountPercent: int|null}
     */
    public function calculatePriceData(): array
    {
        // Цены от количества (MID-308): цена карточки = котировка для одной порции.
        // Ступень приоритетнее ручной скидки и акции. Лесенки нет, модуль выключен
        // или первый порог больше одной порции: прежний расчёт ниже.
        $tierPriceData = $this->tierPriceDataForOnePortion();

        if ($tierPriceData !== null) {
            return $tierPriceData;
        }

        // Сетевой каталог: цена и старая цена берутся из оффера магазина
        // (constrained eager load storeProducts). Если связь не загружена
        // (легаси-режим или выдача без контекста магазина) — фолбэк на
        // колонки products, поведение остаётся прежним.
        $storeContext = $this->resource->storeContext();

        $price = (float) ($storeContext !== null ? $storeContext->price : $this->price);
        $priceOldRaw = $storeContext !== null ? $storeContext->price_old : $this->price_old;
        $priceOld = $priceOldRaw !== null ? (float) $priceOldRaw : null;

        $basePrice = $priceOld ?? $price;
        $currentPrice = round($price, 2);
        $manualDiscount = $priceOld ? ($priceOld - $price) : 0;

        $promotion = $this->resource->getActivePromotion();

        if (! $promotion) {
            // Нет активной акции — возвращаем как есть
            if ($priceOld) {
                $discountPercent = (int) round(100 - ($price / $priceOld * 100));

                return [
                    'price' => $currentPrice,
                    'priceOld' => round($priceOld, 2),
                    'discountPercent' => $discountPercent > 0 ? $discountPercent : null,
                ];
            }

            return [
                'price' => $currentPrice,
                'priceOld' => null,
                'discountPercent' => null,
            ];
        }

        // Есть активная акция — сравниваем скидки
        $promoDiscount = round($basePrice * $promotion->discount_percent / 100, 2);

        if ($promoDiscount >= $manualDiscount) {
            // Акционная скидка больше или равна — применяем её
            $effectivePrice = round($basePrice - $promoDiscount, 2);

            return [
                'price' => $effectivePrice,
                'priceOld' => round((float) $basePrice, 2),
                'discountPercent' => (int) $promotion->discount_percent,
            ];
        }

        // Ручная скидка больше — оставляем как есть
        $discountPercent = (int) round(100 - ($price / $priceOld * 100));

        return [
            'price' => $currentPrice,
            'priceOld' => round($priceOld, 2),
            'discountPercent' => $discountPercent > 0 ? $discountPercent : null,
        ];
    }

    /**
     * @return array{price: float, priceOld: float|null, discountPercent: int|null}|null
     */
    private function tierPriceDataForOnePortion(): ?array
    {
        $resolver = app(ProductPriceResolver::class);

        if ($resolver->portionLadder($this->resource) === []) {
            return null;
        }

        $quote = $resolver->quote($this->resource, 1.0);

        if (! $quote->tierApplied) {
            return null;
        }

        $discountPercent = $quote->priceOld !== null && $quote->priceOld > 0
            ? (int) round(100 - ($quote->price / $quote->priceOld * 100))
            : null;

        return [
            'price' => round($quote->price, 2),
            'priceOld' => $quote->priceOld !== null ? round($quote->priceOld, 2) : null,
            'discountPercent' => $discountPercent !== null && $discountPercent > 0 ? $discountPercent : null,
        ];
    }

    /**
     * Остаток товара для выдачи: в сетевом каталоге — остаток оффера магазина
     * (загруженного контекста), иначе колонка products.quantity.
     */
    private function stockQuantity(): int
    {
        return self::stockOf($this->resource);
    }

    private function getIsFavorite(Request $request): bool
    {
        if (array_key_exists('is_favorite', $this->resource->getAttributes())) {
            return (bool) $this->resource->getAttribute('is_favorite');
        }

        return $this->resource->isFavorite($request->user());
    }
}
