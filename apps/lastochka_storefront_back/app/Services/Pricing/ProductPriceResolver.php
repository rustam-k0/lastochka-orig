<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\CartItem;
use App\Models\Product;
use App\Services\Settings\ModuleConfigService;

/**
 * Единая точка выбора цены товара по количеству (цены от количества, MID-308).
 *
 * Флаг price_tiers выключен или у товара в магазине нет лесенки: котировка
 * равна Product::getEffectivePrice() / getOriginalPrice(), эти методы не
 * меняются. Лесенка есть: ступень приоритетнее ручной скидки и акций.
 */
final class ProductPriceResolver
{
    public function __construct(
        private readonly ModuleConfigService $moduleConfig,
        private readonly ProductTierLadderProvider $ladders,
    ) {}

    public function enabled(): bool
    {
        return $this->moduleConfig->isPriceTiersEnabled();
    }

    public function quote(Product $product, float $portions): PriceQuote
    {
        $price = $product->getEffectivePrice();
        $priceOld = $product->getOriginalPrice();

        $ladder = $this->portionLadder($product);

        if ($ladder === []) {
            return new PriceQuote($price, $priceOld, false);
        }

        $tierQuote = PriceTierSnapshot::build($price, $priceOld, true, $ladder)?->quote($portions);

        if ($tierQuote === null || ! $tierQuote->tierApplied) {
            return new PriceQuote($price, $priceOld, false);
        }

        return new PriceQuote($tierQuote->unitPrice, $tierQuote->unitPriceOld, true);
    }

    /**
     * Снапшот лесенки для позиции заказа: base_price / base_price_old берутся
     * из прежних методов цены, скидочность обычной цены считает вызывающий.
     */
    public function snapshotFor(Product $product, bool $baseIsEligible): ?PriceTierSnapshot
    {
        $ladder = $this->portionLadder($product);

        if ($ladder === []) {
            return null;
        }

        return PriceTierSnapshot::build(
            $product->getEffectivePrice(),
            $product->getOriginalPrice(),
            $baseIsEligible,
            $ladder
        );
    }

    /**
     * Лесенка товара в порциях. Магазин: явный $storeId либо магазин контекста
     * товара ({@see self::storeIdFor()}). [] при выключенном флаге.
     *
     * @return array<int, array{min_quantity: float, price: float}>
     */
    public function portionLadder(Product $product, ?int $storeId = null): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $storeId ??= $this->storeIdFor($product);

        if ($storeId === null || $storeId <= 0) {
            return [];
        }

        return $this->ladders->portionLadder($product, $storeId);
    }

    /**
     * Лесенка для API: minQuantity в единицах поля quantity корзины,
     * price за порцию (семантика поля price карточки).
     *
     * Финальное ревью (F6): порог отдаётся округлённым ВВЕРХ до кратного
     * шагу количества — минимальное количество корзины, при котором ступень
     * реально срабатывает. round() мог отдать порог некратный шагу: порог
     * 2 кг при шаге 300 г → 6.667 порции → round(6.667*300,3) = 2000.1 г,
     * хотя ступень реально включается с 7 порций = 2100 г. Для штучных и
     * для порогов, кратных шагу, значение не меняется. Внутренний выбор
     * ступени ({@see self::quote()}) не трогаем.
     *
     * @return array<int, array{minQuantity: float, price: float}>
     */
    public function tiersForApi(Product $product): array
    {
        $step = max(1, (int) ($product->quantity_step ?? 1));

        return array_map(static fn (array $tier): array => [
            'minQuantity' => round(ceil($tier['min_quantity'] - 0.0005) * $step, 3),
            'price' => $tier['price'],
        ], $this->portionLadder($product));
    }

    public function isTierApplied(Product $product, float $portions, ?int $storeId = null): bool
    {
        $ladder = $this->portionLadder($product, $storeId);

        return $ladder !== [] && $ladder[0]['min_quantity'] <= $portions + 0.0005;
    }

    /**
     * Порции по количеству корзины: то же правило, что CartItem::getPortionsAttribute().
     */
    public function portionsFor(Product $product, int $quantity): float
    {
        $step = (int) ($product->quantity_step ?? 1);

        return (float) ($step > 1 ? intdiv($quantity, $step) : $quantity);
    }

    /**
     * Цена товара за единицу по количеству позиции (MID-308, финальное
     * ревью F10): инкапсулирует portionsFor() + quote()->price — до этого
     * выражение было повторено в четырёх местах (сметы, экспорт сметы в
     * XLSX/PDF, публичная страница сметы).
     */
    public function priceForQuantity(Product $product, int $quantity): float
    {
        return $this->quote($product, $this->portionsFor($product, $quantity))->price;
    }

    /**
     * Итог строки по количеству в единицах корзины: цена порции × число порций.
     * У товара с фасовкой количество — граммы (900 при порции 300 г), и
     * «цена × количество» давало 178.20 × 900 = 160 380 ₽ вместо 534.60.
     */
    public function lineTotalForQuantity(Product $product, int $quantity): float
    {
        return round($this->priceForQuantity($product, $quantity) * $this->portionsFor($product, $quantity), 2);
    }

    /**
     * Магазин лесенки: в сетевом каталоге магазин загруженного оффера
     * ({@see Product::storeContext()}), в обычном режиме магазин товара.
     */
    public function storeIdFor(Product $product): ?int
    {
        if ($this->moduleConfig->isNetworkCatalogEnabled()) {
            $context = $product->storeContext();

            return $context !== null ? (int) $context->store_id : null;
        }

        return $product->store_id !== null ? (int) $product->store_id : null;
    }

    /**
     * Лесенки всей выдачи одним запросом (листинги, вариации, сметы).
     *
     * @param  mixed  $products  коллекция, пагинатор или массив товаров; прочее игнорируется
     */
    public function preloadProducts(mixed $products): void
    {
        if (! is_iterable($products) || ! $this->enabled()) {
            return;
        }

        $pairs = [];

        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }

            $storeId = $this->storeIdFor($product);

            if ($storeId !== null) {
                $pairs[] = [$storeId, (int) $product->id];
            }
        }

        $this->ladders->preload($pairs);
    }

    /**
     * Лесенки набора товаров ОДНОГО заранее известного магазина одним
     * запросом (финальное ревью F2): для мест, где магазин не берётся из
     * контекста, навешенного на товар ({@see self::preloadProducts()}), а
     * известен заранее — например, целевой магазин при перепривязке и
     * слиянии позиций корзины (SwitchCartStoreAction), для которого
     * preloadCartItems() лесенки не грузит (он берёт ТЕКУЩИЙ магазин
     * каждой позиции, а не целевой).
     *
     * @param  iterable<int, int>  $productIds
     */
    public function preloadPairs(int $storeId, iterable $productIds): void
    {
        if (! $this->enabled() || $storeId <= 0) {
            return;
        }

        $pairs = [];

        foreach ($productIds as $productId) {
            $productId = (int) $productId;

            if ($productId > 0) {
                $pairs[] = [$storeId, $productId];
            }
        }

        $this->ladders->preload($pairs);
    }

    /**
     * Лесенки всех позиций корзины одним запросом: в сетевом каталоге по
     * магазину покупки позиции, в обычном режиме по products.store_id.
     *
     * @param  iterable<int, mixed>  $items
     */
    public function preloadCartItems(iterable $items): void
    {
        if (! $this->enabled()) {
            return;
        }

        $network = $this->moduleConfig->isNetworkCatalogEnabled();
        $pairs = [];

        foreach ($items as $item) {
            if (! $item instanceof CartItem || ! $item->relationLoaded('product') || ! $item->product instanceof Product) {
                continue;
            }

            $pairs[] = [
                $network ? (int) $item->store_id : (int) $item->product->store_id,
                (int) $item->product_id,
            ];
        }

        $this->ladders->preload($pairs);
    }
}
